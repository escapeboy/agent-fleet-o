<?php

namespace App\Domain\Website\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks widget cache hit/miss counters for observability.
 *
 * Called from WebsiteWidgetRenderer whenever it resolves a cache key.
 * Counters live in the default cache store (Redis in production, array
 * in tests) with a 24-hour TTL so they roll over daily — good enough
 * for spot-checks and dashboards without committing to a heavy metrics
 * backend.
 *
 * Public API:
 *   - recordHit(string $widget)  — bump hit counter for named widget
 *   - recordMiss(string $widget) — bump miss counter
 *   - snapshot(): array          — return counts, hit rate, per-widget breakdown
 *   - reset(): void              — reset all counters (used by tests)
 *
 * The service is safe to call during public serving (no auth required)
 * because it only writes to the cache store and never reads user data.
 */
class WebsiteWidgetMetrics
{
    public const KEY_PREFIX = 'fleet:widget:metrics:';

    public const TTL_SECONDS = 86400; // 24 hours

    public function recordHit(string $widget): void
    {
        $this->incr($widget, 'hit');
    }

    public function recordMiss(string $widget): void
    {
        $this->incr($widget, 'miss');
    }

    /**
     * @return array{
     *     total_hits: int,
     *     total_misses: int,
     *     hit_rate: float,
     *     per_widget: array<string, array{hits: int, misses: int, hit_rate: float}>
     * }
     */
    public function snapshot(): array
    {
        $perWidget = [];
        $totalHits = 0;
        $totalMisses = 0;

        foreach (WebsiteWidgetRenderer::SUPPORTED as $widget) {
            $hits = (int) Cache::get(self::KEY_PREFIX.$widget.':hit', 0);
            $misses = (int) Cache::get(self::KEY_PREFIX.$widget.':miss', 0);
            $total = $hits + $misses;

            $perWidget[$widget] = [
                'hits' => $hits,
                'misses' => $misses,
                'hit_rate' => $total > 0 ? round($hits / $total, 4) : 0.0,
            ];

            $totalHits += $hits;
            $totalMisses += $misses;
        }

        $grandTotal = $totalHits + $totalMisses;

        return [
            'total_hits' => $totalHits,
            'total_misses' => $totalMisses,
            'hit_rate' => $grandTotal > 0 ? round($totalHits / $grandTotal, 4) : 0.0,
            'per_widget' => $perWidget,
        ];
    }

    public function reset(): void
    {
        foreach (WebsiteWidgetRenderer::SUPPORTED as $widget) {
            Cache::forget(self::KEY_PREFIX.$widget.':hit');
            Cache::forget(self::KEY_PREFIX.$widget.':miss');
        }
    }

    private function incr(string $widget, string $outcome): void
    {
        $key = self::KEY_PREFIX.$widget.':'.$outcome;

        try {
            // Cache::increment returns the new value or false if the key
            // does not exist yet. In that case we seed it with add() to
            // set the TTL correctly.
            $new = Cache::increment($key);

            if ($new === false) {
                Cache::add($key, 1, self::TTL_SECONDS);
            }
        } catch (\Throwable) {
            // Silently discard — metrics failure must not degrade user response.
        }
    }
}
