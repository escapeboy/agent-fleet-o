<?php

namespace App\Infrastructure\AI\LoopDetection\Contracts;

interface TurnHistoryStore
{
    /**
     * Append a turn to a context's history, capped to $window most-recent entries.
     *
     * @param  array{bg: list<string>, oh: string, t: float}  $turn
     */
    public function push(string $contextKey, array $turn, int $window, int $ttlSeconds): void;

    /**
     * Recent turns for a context, newest-first.
     *
     * @return list<array{bg: list<string>, oh: string, t: float}>
     */
    public function recent(string $contextKey): array;
}
