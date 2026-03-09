<?php

namespace App\Infrastructure\Bridge;

use Illuminate\Support\Facades\Redis;

/**
 * Manages in-flight bridge relay requests in Redis.
 *
 * Key schema:
 *   bridge:pending:{request_id}  — String, TTL 90s  — marks request as in-flight
 *   bridge:stream:{request_id}   — List,   TTL 120s — chunk stream (RPUSH / BLPOP)
 *   bridge:usage:{request_id}    — String, TTL 120s — final token usage from daemon
 */
class BridgeRequestRegistry
{
    private const PENDING_TTL = 90;

    private const STREAM_TTL = 120;

    public function register(string $requestId, string $teamId): void
    {
        Redis::connection('bridge')->setex(
            "bridge:pending:{$requestId}",
            self::PENDING_TTL,
            $teamId,
        );
    }

    /**
     * Push a chunk onto the stream list. A JSON-encoded sentinel is pushed on done.
     */
    public function pushChunk(string $requestId, string $chunk, bool $done, ?array $usage = null): void
    {
        $payload = json_encode(['chunk' => $chunk, 'done' => $done, 'usage' => $usage]);

        $conn = Redis::connection('bridge');
        $conn->rpush("bridge:stream:{$requestId}", $payload);
        $conn->expire("bridge:stream:{$requestId}", self::STREAM_TTL);
    }

    /**
     * Store the final usage counts after the stream is complete.
     */
    public function storeUsage(string $requestId, array $usage): void
    {
        Redis::connection('bridge')->setex(
            "bridge:usage:{$requestId}",
            self::STREAM_TTL,
            json_encode($usage),
        );
    }

    /**
     * Blocking pop — waits up to $timeoutSeconds for the next chunk.
     * Returns null on timeout.
     *
     * @return array{chunk: string, done: bool, usage: array|null}|null
     */
    public function popChunk(string $requestId, int $timeoutSeconds = 90): ?array
    {
        $result = Redis::connection('bridge')->blpop(["bridge:stream:{$requestId}"], $timeoutSeconds);

        if (! $result) {
            return null;
        }

        // blpop returns [key, value]
        return json_decode($result[1], true);
    }

    /**
     * Read stored usage (if available). Returns null if not present.
     *
     * @return array{prompt_tokens: int, completion_tokens: int}|null
     */
    public function getUsage(string $requestId): ?array
    {
        $raw = Redis::connection('bridge')->get("bridge:usage:{$requestId}");

        return $raw ? json_decode($raw, true) : null;
    }

    public function isExpired(string $requestId): bool
    {
        return Redis::connection('bridge')->ttl("bridge:pending:{$requestId}") <= 0;
    }
}
