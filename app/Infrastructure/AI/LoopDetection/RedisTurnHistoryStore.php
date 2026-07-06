<?php

namespace App\Infrastructure\AI\LoopDetection;

use App\Infrastructure\AI\LoopDetection\Contracts\TurnHistoryStore;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed ring buffer of recent turn fingerprints, one list per execution
 * context. Uses the 'locks' connection (DB 2) — ephemeral, self-expiring.
 */
class RedisTurnHistoryStore implements TurnHistoryStore
{
    private const PREFIX = 'loopdet:turns:';

    public function push(string $contextKey, array $turn, int $window, int $ttlSeconds): void
    {
        $redis = Redis::connection('locks');
        $key = self::PREFIX.$contextKey;

        $redis->lpush($key, (string) json_encode($turn));
        $redis->ltrim($key, 0, max(0, $window - 1));
        $redis->expire($key, $ttlSeconds);
    }

    public function recent(string $contextKey): array
    {
        $raw = Redis::connection('locks')->lrange(self::PREFIX.$contextKey, 0, -1);

        $turns = [];

        foreach ($raw as $item) {
            $decoded = json_decode((string) $item, true);

            if (is_array($decoded) && isset($decoded['oh'], $decoded['t'])) {
                $turns[] = $decoded;
            }
        }

        return $turns;
    }
}
