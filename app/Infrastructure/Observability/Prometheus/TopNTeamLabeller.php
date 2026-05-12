<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Prometheus;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Resolves a team UUID into a Prometheus-safe label.
 *
 * For FleetQ at 1000+ teams, emitting team_id as a raw label blows up
 * Prometheus cardinality (best practice: <100k unique label combos).
 *
 * Strategy:
 *   1. Keep a Redis sorted set `prometheus:active_teams_zset` that ranks teams
 *      by recent activity (score = increment count over last 24h).
 *   2. The top-N (default 50, configurable) members emit their actual UUID
 *      as the label value.
 *   3. Everyone else collapses to the literal string 'other'.
 *   4. Null team_id (system events) collapses to 'anonymous'.
 *
 * The ranking set is updated by MetricEmitter::recordTeamActivity() right
 * after each emission, and refreshed/trimmed by MetricsSampleCommand every
 * `observability.prometheus.top_n_refresh_seconds` seconds.
 */
final class TopNTeamLabeller
{
    private const ZSET_KEY = 'prometheus:active_teams_zset';

    private const TOP_N_CACHE_KEY = 'prometheus:top_n_set';

    private const TOP_N_CACHE_TTL = 70; // slightly above default 60s refresh

    /** @var array<string, true>|null */
    private ?array $topNCache = null;

    private ?int $topNCacheLoadedAt = null;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly RedisFactory $redis,
    ) {}

    public function label(?string $teamId): string
    {
        if ($teamId === null || $teamId === '') {
            return 'anonymous';
        }

        return $this->isInTopN($teamId) ? $teamId : 'other';
    }

    /**
     * Record activity for ranking. Called by MetricEmitter on every metric
     * emission. Cheap (one ZADD/ZINCRBY) and idempotent.
     */
    public function recordActivity(?string $teamId, int $weight = 1): void
    {
        if ($teamId === null || $teamId === '') {
            return;
        }

        try {
            $this->connection()->zincrby(self::ZSET_KEY, $weight, $teamId);
        } catch (Throwable) {
            // Best-effort. Don't break a metric emission if Redis hiccups.
        }
    }

    /**
     * Refresh the cached top-N set. Called by MetricsSampleCommand on its
     * 15s heartbeat; also called lazily inside isInTopN() on cache miss.
     */
    public function refreshTopN(): void
    {
        $topN = (int) $this->config->get('observability.prometheus.top_n_teams', 50);

        try {
            $members = $this->connection()->zrevrange(self::ZSET_KEY, 0, $topN - 1);

            $payload = json_encode($members ?: []);
            $this->connection()->setex(self::TOP_N_CACHE_KEY, self::TOP_N_CACHE_TTL, $payload);

            $this->topNCache = array_flip($members ?: []);
            $this->topNCacheLoadedAt = time();
        } catch (Throwable) {
            // Best-effort.
        }
    }

    /**
     * Optional housekeeping: shrinks the sorted set so it doesn't grow
     * unbounded. Called by MetricsSampleCommand once per hour.
     */
    public function trimRanking(int $keepTop = 1000): void
    {
        try {
            $cardinality = (int) $this->connection()->zcard(self::ZSET_KEY);
            if ($cardinality > $keepTop) {
                // Drop the lowest-ranked members beyond keepTop.
                $this->connection()->zremrangebyrank(self::ZSET_KEY, 0, $cardinality - $keepTop - 1);
            }
        } catch (Throwable) {
            // Best-effort.
        }
    }

    private function isInTopN(string $teamId): bool
    {
        $this->ensureTopNCache();

        return isset($this->topNCache[$teamId]);
    }

    private function ensureTopNCache(): void
    {
        // In-process cache: 10s lifetime to keep horizon worker hot path fast.
        if ($this->topNCache !== null && $this->topNCacheLoadedAt !== null
            && time() - $this->topNCacheLoadedAt < 10) {
            return;
        }

        try {
            $cached = $this->connection()->get(self::TOP_N_CACHE_KEY);
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    $this->topNCache = array_flip($decoded);
                    $this->topNCacheLoadedAt = time();

                    return;
                }
            }
        } catch (Throwable) {
            // Fall through to refresh.
        }

        $this->refreshTopN();
    }

    private function connection()
    {
        $connectionName = (string) $this->config->get('observability.prometheus.redis_connection', 'cache');

        return $this->redis->connection($connectionName);
    }
}
