<?php

namespace App\Domain\Assistant\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Tracks per-conversation tool call counts in Redis.
 *
 * Used to detect and surface "search flood" loops where the LLM repeatedly
 * calls the same tool without making progress. When a tool approaches its
 * configured warn threshold, a <tool_budget> hint is injected into the
 * assistant system prompt.
 *
 * Keys expire after 24 hours, matching the typical session lifetime.
 */
class ToolUsageTracker
{
    private const KEY_PREFIX = 'tool_usage:';

    private const TTL = 86400; // 24 hours

    /**
     * Increment the call count for a tool in a conversation.
     * Returns the new count.
     */
    public function increment(string $conversationId, string $toolName): int
    {
        // Reject malformed tool names (e.g. from a prompt-injected LLM response)
        // to prevent Redis hash field pollution.
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $toolName)) {
            return 0;
        }

        $key = self::KEY_PREFIX.$conversationId;
        $count = (int) Redis::hincrby($key, $toolName, 1);
        Redis::expire($key, self::TTL);

        return $count;
    }

    /**
     * Get all tool call counts for a conversation.
     *
     * @return array<string, int>
     */
    public function getUsage(string $conversationId): array
    {
        $raw = Redis::hgetall(self::KEY_PREFIX.$conversationId);

        if (empty($raw)) {
            return [];
        }

        return array_map('intval', $raw);
    }

    /**
     * Reset all counters for a conversation (e.g. after clearing).
     */
    public function reset(string $conversationId): void
    {
        Redis::del(self::KEY_PREFIX.$conversationId);
    }

    /**
     * Build a <tool_budget> XML hint to inject into the system prompt when
     * any tool exceeds its warn threshold. Returns null when no tools are near
     * their limits.
     */
    public function buildBudgetHint(string $conversationId): ?string
    {
        if (! config('context_compaction.tool_throttle_enabled', true)) {
            return null;
        }

        $usage = $this->getUsage($conversationId);
        if (empty($usage)) {
            return null;
        }

        $limits = $this->thresholds();
        $lines = [];

        foreach ($usage as $tool => $count) {
            $pattern = $this->matchPattern($tool, $limits);
            if ($pattern === null) {
                continue;
            }

            $warnAt = $pattern['warn'];
            $softAt = $pattern['soft'];

            if ($count >= $warnAt) {
                $remaining = max(0, $softAt - $count);
                $lines[] = "  {$tool}: called {$count}× — {$remaining} calls remaining before results reduce. Prefer reasoning from already-retrieved context.";
            }
        }

        if (empty($lines)) {
            return null;
        }

        return "<tool_budget>\n".implode("\n", $lines)."\n</tool_budget>";
    }

    /**
     * Return a reduced result limit for a tool that has hit its soft threshold.
     * Returns null when no limit applies.
     */
    public function softLimit(string $conversationId, string $toolName): ?int
    {
        if (! config('context_compaction.tool_throttle_enabled', true)) {
            return null;
        }

        $usage = $this->getUsage($conversationId);
        $count = $usage[$toolName] ?? 0;
        $limits = $this->thresholds();
        $pattern = $this->matchPattern($toolName, $limits);

        if ($pattern === null || $count < $pattern['soft']) {
            return null;
        }

        return $pattern['reduced_limit'];
    }

    /**
     * Per-tool warn / soft thresholds and reduced result counts.
     *
     * @return array<string, array{warn: int, soft: int, reduced_limit: int}>
     */
    private function thresholds(): array
    {
        return [
            'list_*' => ['warn' => 5, 'soft' => 10, 'reduced_limit' => 3],
            'memory_search' => ['warn' => 4, 'soft' => 8, 'reduced_limit' => 3],
            'search_*' => ['warn' => 5, 'soft' => 10, 'reduced_limit' => 3],
        ];
    }

    /**
     * Find a threshold pattern that matches the given tool name.
     *
     * @param  array<string, array{warn: int, soft: int, reduced_limit: int}>  $limits
     * @return array{warn: int, soft: int, reduced_limit: int}|null
     */
    private function matchPattern(string $toolName, array $limits): ?array
    {
        // Exact match first
        if (isset($limits[$toolName])) {
            return $limits[$toolName];
        }

        // Wildcard suffix match (e.g. "list_*" matches "list_experiments")
        foreach ($limits as $pattern => $threshold) {
            if (str_ends_with($pattern, '*')) {
                $prefix = rtrim($pattern, '*');
                if (str_starts_with($toolName, $prefix)) {
                    return $threshold;
                }
            }
        }

        return null;
    }
}
