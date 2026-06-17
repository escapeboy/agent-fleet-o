<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Jobs\ComputeProviderRankingJob;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Infrastructure\AI\Services\ProviderRanker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The ranking job derives a per-(provider,model) success rate from the full
 * completed+failed denominator (health-mode routing input), while latency/cost
 * still come from completed rows only.
 */
class ComputeProviderRankingJobTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();

        $keys = ProviderRanker::storageKeys();
        $redis = Redis::connection('cache');
        foreach ($keys as $key) {
            $redis->del($key);
        }
    }

    public function test_success_rate_is_computed_from_completed_and_failed(): void
    {
        // 12 completed (with timing) + 4 failed → success = 12/16 = 0.75.
        for ($i = 0; $i < 12; $i++) {
            $this->log('completed', latency: 300, cost: 100, tokens: 1000);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->log('failed');
        }

        (new ComputeProviderRankingJob)->handle();

        $keys = ProviderRanker::storageKeys();
        $redis = Redis::connection('cache');
        $field = 'anthropic:claude-sonnet-4-5';

        $this->assertEqualsWithDelta(0.75, (float) $redis->hget($keys['success'], $field), 0.0001);
        // Latency still populated from the 12 completed rows.
        $this->assertSame('300', (string) $redis->hget($keys['latency'], $field));
    }

    public function test_below_total_threshold_writes_no_success_metric(): void
    {
        // Only 5 total rows (< MIN_SAMPLES) → not eligible for health ranking.
        for ($i = 0; $i < 3; $i++) {
            $this->log('completed', latency: 300, cost: 100, tokens: 1000);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->log('failed');
        }

        (new ComputeProviderRankingJob)->handle();

        $keys = ProviderRanker::storageKeys();
        $redis = Redis::connection('cache');

        $this->assertSame(0, (int) $redis->hexists($keys['success'], 'anthropic:claude-sonnet-4-5'));
    }

    private function log(string $status, int $latency = 0, int $cost = 0, int $tokens = 0): void
    {
        LlmRequestLog::create([
            'team_id' => $this->team->id,
            'idempotency_key' => (string) Str::uuid7(),
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'status' => $status,
            'input_tokens' => (int) ($tokens / 2),
            'output_tokens' => (int) ($tokens / 2),
            'cost_credits' => $cost,
            'latency_ms' => $latency,
        ]);
    }
}
