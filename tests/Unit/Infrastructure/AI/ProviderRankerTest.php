<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Services\ProviderRanker;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ProviderRankerTest extends TestCase
{
    private ProviderRanker $ranker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ranker = new ProviderRanker;

        $keys = ProviderRanker::storageKeys();
        $redis = Redis::connection('cache');
        $redis->del($keys['latency']);
        $redis->del($keys['cost']);
        $redis->del($keys['samples']);
    }

    public function test_null_sort_returns_input_unchanged(): void
    {
        $chain = [
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ['provider' => 'openai', 'model' => 'gpt-4o'],
        ];

        $this->assertSame($chain, $this->ranker->rank($chain, null));
    }

    public function test_invalid_sort_returns_input_unchanged(): void
    {
        $chain = [
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ['provider' => 'openai', 'model' => 'gpt-4o'],
        ];

        $this->assertSame($chain, $this->ranker->rank($chain, 'foobar'));
    }

    public function test_single_entry_chain_returns_unchanged(): void
    {
        $chain = [['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5']];

        $this->assertSame($chain, $this->ranker->rank($chain, 'cost'));
    }

    public function test_rank_by_latency_uses_p50_ascending(): void
    {
        $this->seedMetrics([
            'anthropic:claude-sonnet-4-5' => ['latency' => 800, 'cost' => 5.0, 'samples' => 50],
            'openai:gpt-4o' => ['latency' => 200, 'cost' => 7.0, 'samples' => 50],
            'google:gemini-2.5-pro' => ['latency' => 500, 'cost' => 4.0, 'samples' => 50],
        ]);

        $chain = [
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ['provider' => 'openai', 'model' => 'gpt-4o'],
            ['provider' => 'google', 'model' => 'gemini-2.5-pro'],
        ];

        $ranked = $this->ranker->rank($chain, 'latency');

        $this->assertSame('openai', $ranked[0]['provider']);
        $this->assertSame('google', $ranked[1]['provider']);
        $this->assertSame('anthropic', $ranked[2]['provider']);
    }

    public function test_rank_by_cost_uses_median_per_1k_ascending(): void
    {
        $this->seedMetrics([
            'anthropic:claude-sonnet-4-5' => ['latency' => 800, 'cost' => 5.0, 'samples' => 50],
            'openai:gpt-4o' => ['latency' => 200, 'cost' => 7.0, 'samples' => 50],
            'google:gemini-2.5-pro' => ['latency' => 500, 'cost' => 4.0, 'samples' => 50],
        ]);

        $chain = [
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ['provider' => 'openai', 'model' => 'gpt-4o'],
            ['provider' => 'google', 'model' => 'gemini-2.5-pro'],
        ];

        $ranked = $this->ranker->rank($chain, 'cost');

        $this->assertSame('google', $ranked[0]['provider']);
        $this->assertSame('anthropic', $ranked[1]['provider']);
        $this->assertSame('openai', $ranked[2]['provider']);
    }

    public function test_below_sample_threshold_falls_to_end_preserving_input_order(): void
    {
        $this->seedMetrics([
            'anthropic:claude-sonnet-4-5' => ['latency' => 800, 'cost' => 5.0, 'samples' => 50],
            'openai:gpt-4o' => ['latency' => 100, 'cost' => 7.0, 'samples' => 3], // below threshold
        ]);

        $chain = [
            ['provider' => 'openai', 'model' => 'gpt-4o'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
        ];

        $ranked = $this->ranker->rank($chain, 'latency');

        // anthropic has metric → comes first; openai (below threshold) goes last
        $this->assertSame('anthropic', $ranked[0]['provider']);
        $this->assertSame('openai', $ranked[1]['provider']);
    }

    public function test_missing_entries_fall_to_end_in_original_order(): void
    {
        $this->seedMetrics([
            'anthropic:claude-sonnet-4-5' => ['latency' => 800, 'cost' => 5.0, 'samples' => 50],
        ]);

        $chain = [
            ['provider' => 'openai', 'model' => 'gpt-4o'],            // missing
            ['provider' => 'google', 'model' => 'gemini-2.5-pro'],    // missing
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
        ];

        $ranked = $this->ranker->rank($chain, 'cost');

        $this->assertSame('anthropic', $ranked[0]['provider']);
        $this->assertSame('openai', $ranked[1]['provider']); // first missing in input order
        $this->assertSame('google', $ranked[2]['provider']);
    }

    /**
     * @param  array<string, array{latency: int, cost: float, samples: int}>  $metrics
     */
    private function seedMetrics(array $metrics): void
    {
        $keys = ProviderRanker::storageKeys();
        $redis = Redis::connection('cache');

        foreach ($metrics as $field => $values) {
            $redis->hset($keys['latency'], $field, (string) $values['latency']);
            $redis->hset($keys['cost'], $field, (string) $values['cost']);
            $redis->hset($keys['samples'], $field, (string) $values['samples']);
        }
    }
}
