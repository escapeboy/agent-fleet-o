<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\Services\ContextHealthService;
use App\Infrastructure\AI\Services\TokenEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Context windows per the claude-api skill model table (cached 2026-10-06):
 * Opus 4.7 / Opus 4.6 / Sonnet 4.6 and every 5.x model are 1M, Haiku 4.5 is 200K.
 * A 200K figure for a 1M model makes context health report "critical" at 180K
 * tokens, a fifth of the real window.
 */
class ContextHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, int}> */
    public static function windows(): array
    {
        return [
            'opus 4.7' => ['claude-opus-4-7', 1_000_000],
            'opus 4.6' => ['claude-opus-4-6', 1_000_000],
            'sonnet 4.6' => ['claude-sonnet-4-6', 1_000_000],
            'sonnet 5.5' => ['claude-sonnet-5-5', 1_000_000],
            'haiku 5.5' => ['claude-haiku-5-5', 1_000_000],
            'haiku 4.5 dated' => ['claude-haiku-4-5-20251001', 200_000],
            'gpt-4o' => ['gpt-4o', 128_000],
        ];
    }

    #[DataProvider('windows')]
    public function test_experiment_context_window_matches_the_model(string $model, int $window): void
    {
        $experiment = Experiment::factory()->create();
        DB::table('llm_request_logs')->insert([
            'id' => (string) Str::uuid7(),
            'idempotency_key' => (string) Str::uuid7(),
            'team_id' => $experiment->team_id,
            'agent_id' => Agent::factory()->create(['team_id' => $experiment->team_id])->id,
            'experiment_id' => $experiment->id,
            'provider' => 'anthropic',
            'model' => $model,
            'input_tokens' => 1_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $health = app(ContextHealthService::class)->getExperimentContextHealth($experiment);

        $this->assertSame($model, $health->primaryModel);
        $this->assertSame($window, $health->contextWindowTokens);
    }

    public function test_token_estimator_knows_opus_4_6_is_1m(): void
    {
        $this->assertSame(1_000_000, (new TokenEstimator)->getModelContextLimit('claude-opus-4-6'));
    }
}
