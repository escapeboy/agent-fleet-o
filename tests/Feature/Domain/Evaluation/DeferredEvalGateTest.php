<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Domain\Evaluation\Enums\EvaluationCaseStatus;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Services\LlmJudge;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DeferredEvalGateTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create(['name' => 'DG', 'slug' => 'dg-'.uniqid(), 'owner_id' => $user->id, 'settings' => []]);
        $user->update(['current_team_id' => $this->team->id]);
    }

    private function bindGatewayAndScoreByInput(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: 'answer', parsedOutput: null, usage: new AiUsageDTO(10, 5, 1),
            provider: 'anthropic', model: 'm', latencyMs: 10, schemaValid: true, cached: false,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        // High score when the input mentions "win", low otherwise.
        $judge = Mockery::mock(LlmJudge::class);
        $judge->shouldReceive('evaluate')->andReturnUsing(function (string $criterion, string $input) {
            return ['score' => str_contains($input, 'win') ? 9.0 : 3.0, 'reasoning' => 'x', 'cost_credits' => 1];
        });
        $this->app->instance(LlmJudge::class, $judge);
    }

    private function dataset(): EvaluationDataset
    {
        return EvaluationDataset::create([
            'team_id' => $this->team->id, 'name' => 'gate set', 'case_count' => 0,
        ]);
    }

    private function addCase(EvaluationDataset $d, string $input, EvaluationCaseStatus $status): void
    {
        EvaluationCase::create([
            'dataset_id' => $d->id, 'team_id' => $this->team->id, 'input' => $input,
            'expected_output' => 'e', 'status' => $status, 'metadata' => [],
        ]);
    }

    public function test_deferred_passing_case_is_a_silent_win_not_a_pass(): void
    {
        $d = $this->dataset();
        $this->addCase($d, 'active question', EvaluationCaseStatus::Active);   // scores 3 → fails gate
        $this->addCase($d, 'deferred win question', EvaluationCaseStatus::Deferred); // scores 9 → silent win
        $this->bindGatewayAndScoreByInput();

        $run = app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id, datasetId: $d->id, targetProvider: 'anthropic', targetModel: 'm',
        );

        $s = $run->summary;
        $this->assertSame(0, $s['passed']);
        $this->assertSame(1, $s['failed']);            // only the active case gates
        $this->assertSame(1, $s['deferred_count']);
        $this->assertSame(1, $s['deferred_passed']);
        $this->assertCount(1, $s['silent_wins']);
        $this->assertEqualsWithDelta(0.0, $s['pass_rate_pct'], 0.001); // 0 of 1 gating
    }

    public function test_deferred_failing_case_does_not_pollute_the_gate(): void
    {
        $d = $this->dataset();
        $this->addCase($d, 'active win question', EvaluationCaseStatus::Active);   // scores 9 → passes
        $this->addCase($d, 'deferred bad question', EvaluationCaseStatus::Deferred); // scores 3 → not a silent win
        $this->bindGatewayAndScoreByInput();

        $run = app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id, datasetId: $d->id, targetProvider: 'anthropic', targetModel: 'm',
        );

        $s = $run->summary;
        $this->assertSame(1, $s['passed']);
        $this->assertSame(0, $s['failed']);
        $this->assertSame(1, $s['deferred_count']);
        $this->assertSame(0, $s['deferred_passed']);
        $this->assertCount(0, $s['silent_wins']);
        $this->assertEqualsWithDelta(100.0, $s['pass_rate_pct'], 0.001); // 1 of 1 gating
    }
}
