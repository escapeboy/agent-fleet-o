<?php

namespace App\Infrastructure\AI\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Reorders a fallback chain by 24h-rolling provider performance metrics.
 *
 * Metrics are computed periodically by ComputeProviderRankingJob and cached
 * in Redis DB1 (cache connection). Hot path performs at most N hash reads
 * (one per chain entry) — well under 5ms p99 for typical 3–5 element chains.
 *
 * Cross-team aggregation is intentional: ranking quality grows with sample size,
 * and a single team's request volume rarely yields enough data points alone.
 *
 * v1 supports two sort modes:
 *   - 'cost'    — ascending by median platform_credits per 1k tokens
 *   - 'latency' — ascending by p50 wall-clock latency in milliseconds
 *
 * Entries below the sample threshold (or absent entirely) sort to the end of
 * the chain, preserving stability for cold providers.
 */
class ProviderRanker
{
    private const HASH_LATENCY = 'gateway:ranker:p50_latency_ms';

    private const HASH_COST = 'gateway:ranker:cost_per_1k_credits';

    private const HASH_SAMPLES = 'gateway:ranker:sample_count';

    public const MIN_SAMPLES = 10;

    /**
     * @param  list<array{provider: string, model: string}>  $chain
     * @return list<array{provider: string, model: string}>
     */
    public function rank(array $chain, ?string $sortBy): array
    {
        if (! in_array($sortBy, ['cost', 'latency'], true)) {
            return $chain;
        }

        if (count($chain) <= 1) {
            return $chain;
        }

        $hashKey = $sortBy === 'cost' ? self::HASH_COST : self::HASH_LATENCY;
        $redis = Redis::connection('cache');

        $scored = array_map(
            function (array $target) use ($redis, $hashKey): array {
                $field = $target['provider'].':'.$target['model'];
                $samples = (int) ($redis->hget(self::HASH_SAMPLES, $field) ?? 0);

                if ($samples < self::MIN_SAMPLES) {
                    return ['target' => $target, 'metric' => null];
                }

                $value = $redis->hget($hashKey, $field);

                return [
                    'target' => $target,
                    'metric' => is_numeric($value) ? (float) $value : null,
                ];
            },
            $chain,
        );

        // Stable sort: present metrics ascending, missing metrics keep original order at the tail.
        $present = [];
        $missing = [];

        foreach ($scored as $index => $entry) {
            if ($entry['metric'] === null) {
                $missing[] = ['target' => $entry['target'], 'index' => $index];
            } else {
                $present[] = ['target' => $entry['target'], 'metric' => $entry['metric'], 'index' => $index];
            }
        }

        usort(
            $present,
            fn (array $a, array $b): int => $a['metric'] === $b['metric']
                ? $a['index'] <=> $b['index']
                : ($a['metric'] <=> $b['metric']),
        );

        usort($missing, fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return array_values(array_map(
            fn (array $entry): array => $entry['target'],
            array_merge($present, $missing),
        ));
    }

    /**
     * Storage hash names — exposed so ComputeProviderRankingJob writes to the same keys.
     *
     * @return array{latency: string, cost: string, samples: string}
     */
    public static function storageKeys(): array
    {
        return [
            'latency' => self::HASH_LATENCY,
            'cost' => self::HASH_COST,
            'samples' => self::HASH_SAMPLES,
        ];
    }
}
