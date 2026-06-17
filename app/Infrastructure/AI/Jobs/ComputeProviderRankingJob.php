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
        // Pull both completed and failed rows: latency/cost come from completed
        // rows only (failed rows carry no timing), but the success rate needs
        // the full completed+failed denominator per provider/model.
        $rows = LlmRequestLog::withoutGlobalScopes()
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', ['completed', 'failed'])
            ->whereNotNull('provider')
            ->whereNotNull('model')
            ->get(['provider', 'model', 'status', 'latency_ms', 'input_tokens', 'output_tokens', 'cost_credits']);

        $byKey = [];

        foreach ($rows as $row) {
            $key = $row->provider.':'.$row->model;
            $byKey[$key]['total'] = ($byKey[$key]['total'] ?? 0) + 1;

            if ($row->status !== 'completed') {
                continue;
            }

            $byKey[$key]['completed'] = ($byKey[$key]['completed'] ?? 0) + 1;

            $tokens = (int) $row->input_tokens + (int) $row->output_tokens;
            if ($row->latency_ms !== null && $row->cost_credits !== null && $tokens > 0) {
                $byKey[$key]['latency'][] = (int) $row->latency_ms;
                $byKey[$key]['cost_per_1k'][] = (float) $row->cost_credits / $tokens * 1000.0;
            }
        }

        $keys = ProviderRanker::storageKeys();
        $redis = Redis::connection('cache');

        $writeBatch = [
            $keys['latency'] => [],
            $keys['cost'] => [],
            $keys['samples'] => [],
            $keys['success'] => [],
        ];

        foreach ($byKey as $field => $bucket) {
            // Health: success rate over the full completed+failed denominator,
            // eligible once total samples clear the threshold.
            $total = (int) ($bucket['total'] ?? 0);
            if ($total >= ProviderRanker::MIN_SAMPLES) {
                $completed = (int) ($bucket['completed'] ?? 0);
                $writeBatch[$keys['success']][$field] = round($completed / $total, 4);
            }

            // Latency/cost: gated on the count of completed rows that carried timing.
            $samples = count($bucket['latency'] ?? []);
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

        $redis->setex('gateway:ranker:computed_at', 600, now()->toIso8601String());

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
