<?php

namespace App\Infrastructure\AI\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Rolling per-team daily counters for eval-grounded routing in SHADOW mode.
 *
 * The shadow middleware records every advisory recommendation here; the AI
 * Routing page reads the window's totals. Pure telemetry — never gates a
 * request — so small races under concurrency are acceptable. Backed by Redis
 * hashes with a short TTL; one hash per (team, day).
 */
class EvalShadowCounters
{
    private const PREFIX = 'eval_shadow';

    // Longest supported dashboard window (30d) plus a buffer day.
    private const TTL_SECONDS = 31 * 86400;

    /**
     * @param  array{would_downgrade?: bool, est_savings_per_call?: float|int}  $recommendation
     */
    public function record(string $teamId, array $recommendation): void
    {
        $key = $this->key($teamId, now()->format('Ymd'));

        Redis::hincrby($key, 'total', 1);

        if (! empty($recommendation['would_downgrade'])) {
            Redis::hincrby($key, 'would_downgrade', 1);
            $saving = (int) round((float) ($recommendation['est_savings_per_call'] ?? 0));
            if ($saving > 0) {
                Redis::hincrby($key, 'est_savings_credits', $saving);
            }
        }

        Redis::expire($key, self::TTL_SECONDS);
    }

    /**
     * Sum the per-day counters across the trailing window.
     *
     * @return array{total: int, would_downgrade: int, est_savings_credits: int}
     */
    public function totals(string $teamId, int $days): array
    {
        $total = 0;
        $downgrade = 0;
        $savings = 0;

        for ($i = 0; $i < max(1, $days); $i++) {
            $hash = Redis::hgetall($this->key($teamId, now()->subDays($i)->format('Ymd')));
            if (! $hash) {
                continue;
            }
            $total += (int) ($hash['total'] ?? 0);
            $downgrade += (int) ($hash['would_downgrade'] ?? 0);
            $savings += (int) ($hash['est_savings_credits'] ?? 0);
        }

        return [
            'total' => $total,
            'would_downgrade' => $downgrade,
            'est_savings_credits' => $savings,
        ];
    }

    private function key(string $teamId, string $day): string
    {
        return self::PREFIX.":{$teamId}:{$day}";
    }
}
