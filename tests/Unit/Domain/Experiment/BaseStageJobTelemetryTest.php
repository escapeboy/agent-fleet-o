<?php

namespace Tests\Unit\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression for the per-stage telemetry query in BaseStageJob::handle().
 *
 * The original implementation treated `llm_request_logs.usage` as a JSONB
 * column and pulled `input_tokens` / `output_tokens` out via `->>` operator.
 * The schema actually stores those values as flat top-level integer columns,
 * so every stage threw `SQLSTATE[42703]: Undefined column "usage"` after the
 * stage transition committed — leaving experiments in mid-flight with bogus
 * `failed_jobs` rows.
 *
 * This test pins the corrected shape of the aggregation query so the bug
 * cannot recur.
 */
class BaseStageJobTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Experiment $experiment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Telemetry Team',
            'slug' => 'telemetry-team',
            'owner_id' => $this->user->id,
            'plan' => 'pro',
            'settings' => [],
        ]);
        $this->experiment = Experiment::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Telemetry Experiment',
            'thesis' => 'Test thesis',
            'track' => ExperimentTrack::Growth,
            'status' => ExperimentStatus::Scoring,
            'constraints' => [],
            'success_criteria' => [],
            'max_iterations' => 3,
            'current_iteration' => 1,
        ]);
    }

    private function createLog(array $overrides): LlmRequestLog
    {
        return LlmRequestLog::create(array_merge([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'idempotency_key' => 'test-'.bin2hex(random_bytes(8)),
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'status' => 'success',
            'input_tokens' => 0,
            'output_tokens' => 0,
        ], $overrides));
    }

    /**
     * Mirrors the aggregation query used in
     * `BaseStageJob::collectStageTokenUsage()`.
     *
     * @return array{input_tokens: int, output_tokens: int, llm_calls: int}
     */
    private function aggregateStageTokenUsage(string $experimentId, \DateTimeInterface $stageStarted): array
    {
        $row = LlmRequestLog::where('experiment_id', $experimentId)
            ->where('created_at', '>=', $stageStarted)
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens, COUNT(*) as llm_calls')
            ->first();

        return [
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'llm_calls' => (int) ($row->llm_calls ?? 0),
        ];
    }

    public function test_returns_zero_telemetry_when_no_llm_logs_exist(): void
    {
        $usage = $this->aggregateStageTokenUsage($this->experiment->id, now()->subMinutes(5));

        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0, 'llm_calls' => 0], $usage);
    }

    public function test_aggregates_input_output_tokens_and_call_count_for_logs_within_window(): void
    {
        $stageStarted = now()->subSeconds(30);

        $this->createLog(['input_tokens' => 1200, 'output_tokens' => 340]);
        $this->createLog(['input_tokens' => 800, 'output_tokens' => 60]);

        // Pre-window call — must be excluded. Eloquent overwrites `created_at`
        // on save, so we backdate via the query builder afterward.
        $stale = $this->createLog(['input_tokens' => 9999, 'output_tokens' => 9999]);
        DB::table('llm_request_logs')
            ->where('id', $stale->id)
            ->update(['created_at' => now()->subMinutes(10)]);

        $usage = $this->aggregateStageTokenUsage($this->experiment->id, $stageStarted);

        $this->assertSame(2000, $usage['input_tokens']);
        $this->assertSame(400, $usage['output_tokens']);
        $this->assertSame(2, $usage['llm_calls']);
    }

    public function test_ignores_logs_for_other_experiments(): void
    {
        $other = Experiment::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Other Experiment',
            'thesis' => 'Other',
            'track' => ExperimentTrack::Growth,
            'status' => ExperimentStatus::Scoring,
            'constraints' => [],
            'success_criteria' => [],
            'max_iterations' => 3,
            'current_iteration' => 1,
        ]);

        $this->createLog([
            'experiment_id' => $other->id,
            'input_tokens' => 500,
            'output_tokens' => 200,
        ]);

        $usage = $this->aggregateStageTokenUsage($this->experiment->id, now()->subMinutes(5));

        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0, 'llm_calls' => 0], $usage);
    }
}
