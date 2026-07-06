<?php

namespace Tests\Support\LoopDetection;

use App\Infrastructure\AI\LoopDetection\Contracts\TurnHistoryStore;

/**
 * In-memory TurnHistoryStore for tests — avoids a Redis dependency while
 * preserving the newest-first, window-capped semantics of the Redis impl.
 */
class ArrayTurnHistoryStore implements TurnHistoryStore
{
    /** @var array<string, list<array>> */
    private array $data = [];

    public function push(string $contextKey, array $turn, int $window, int $ttlSeconds): void
    {
        $existing = $this->data[$contextKey] ?? [];
        array_unshift($existing, $turn);
        $this->data[$contextKey] = array_slice($existing, 0, $window);
    }

    public function recent(string $contextKey): array
    {
        return $this->data[$contextKey] ?? [];
    }
}
