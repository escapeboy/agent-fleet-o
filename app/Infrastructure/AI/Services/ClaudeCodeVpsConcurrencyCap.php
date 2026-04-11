<?php

namespace App\Infrastructure\AI\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Per-team concurrency cap for VPS Claude Code invocations.
 *
 * Uses a Redis sorted-set on the 'locks' connection so that slot tokens can be
 * expired safely (stale locks don't strand slots forever even if `release()`
 * is never called) and each call owns a unique token for idempotent release.
 */
class ClaudeCodeVpsConcurrencyCap
{
    private const KEY_PREFIX = 'claude-vps:active:';

    private const SLOT_TTL_SECONDS = 600;

    public function cap(): int
    {
        return max(1, (int) config('local_agents.vps.max_concurrency_per_team', 2));
    }

    /**
     * Try to acquire a slot for a team. Returns a token on success, false on cap.
     */
    public function acquire(string $teamId): string|false
    {
        $redis = Redis::connection('locks');
        $key = $this->key($teamId);
        $now = microtime(true);
        $cutoff = $now - self::SLOT_TTL_SECONDS;

        $redis->zremrangebyscore($key, '-inf', $cutoff);

        $current = (int) $redis->zcard($key);
        if ($current >= $this->cap()) {
            return false;
        }

        $token = (string) Str::uuid();
        $redis->zadd($key, $now, $token);
        $redis->expire($key, self::SLOT_TTL_SECONDS);

        return $token;
    }

    public function release(string $teamId, string $token): void
    {
        Redis::connection('locks')->zrem($this->key($teamId), $token);
    }

    public function activeCount(string $teamId): int
    {
        $redis = Redis::connection('locks');
        $key = $this->key($teamId);
        $redis->zremrangebyscore($key, '-inf', microtime(true) - self::SLOT_TTL_SECONDS);

        return (int) $redis->zcard($key);
    }

    private function key(string $teamId): string
    {
        return self::KEY_PREFIX.$teamId;
    }
}
