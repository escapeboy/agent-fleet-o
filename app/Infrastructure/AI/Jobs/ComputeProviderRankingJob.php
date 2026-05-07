<?php

namespace App\Infrastructure\AI\Jobs;

use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Infrastructure\AI\Services\ProviderRanker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Aggregates 24h of llm_request_logs into per-(provider, model) median latency
 * and median cost-per-1k-tokens. Writes to Redis DB1 hashes consumed by
 * ProviderRanker on the request hot path.
 *
 * Runs every 5 minutes via the scheduler. Idle teams are not affected — the
 * job runs once globally, not per-team.
 */
class ComputeProviderRankingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(): void
    {
        $rows = LlmRequestLog::withoutGlobalScopes()
            ->where('created_at', '>=', now()->subDay())
            ->where('status', 'completed')
            ->whereNotNull('provider')
            ->whereNotNull('model')
            ->whereNotNull('latency_ms')
            ->whereNotNull('input_tokens')
            ->whereNotNull('output_tokens')
            ->whereNotNull('cost_credits')
            ->get(['provider', 'model', 'latency_ms', 'input_tokens', 'output_tokens', 'cost_credits']);

        $byKey = [];

        foreach ($rows as $row) {
            $tokens = (int) $row->input_tokens + (int) $row->output_tokens;
            if ($tokens <= 0) {
                continue;
            }

            $key = $row->provider.':'.$row->model;
            $byKey[$key]['latency'][] = (int) $row->latency_ms;
            $byKey[$key]['cost_per_1k'][] = (float) $row->cost_credits / $tokens * 1000.0;
        }

        $keys = ProviderRanker::storageKeys();
        $redis = Redis::connection('cache');

        $writeBatch = [
            $keys['latency'] => [],
            $keys['cost'] => [],
            $keys['samples'] => [],
        ];

        foreach ($byKey as $field => $bucket) {
            $samples = count($bucket['latency']);
            if ($samples < ProviderRanker::MIN_SAMPLES) {
                continue;
            }

            $writeBatch[$keys['latency']][$field] = (int) round($this->median($bucket['latency']));
            $writeBatch[$keys['cost']][$field] = round($this->median($bucket['cost_per_1k']), 4);
            $writeBatch[$keys['samples']][$field] = $samples;
        }

        // Replace each hash atomically per metric to avoid partial-state reads.
        foreach ($writeBatch as $hashKey => $entries) {
            if ($entries === []) {
                $redis->del($hashKey);

                continue;
            }

            $redis->del($hashKey);
            $redis->hmset($hashKey, $entries);
            $redis->expire($hashKey, 600);
        }

        Redis::connection('cache')->set('gateway:ranker:computed_at', now()->toIso8601String(), ['EX' => 600]);

        Log::debug('provider_ranker_computed', [
            'eligible_pairs' => count($writeBatch[$keys['samples']]),
            'total_rows' => $rows->count(),
        ]);
    }

    /**
     * @param  list<int|float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = (int) floor($count / 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0;
    }
}
