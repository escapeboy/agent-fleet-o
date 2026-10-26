<?php

namespace App\Infrastructure\AI\Services;

class TokenEstimator
{
    /** Characters per token for natural language text. */
    private const CHARS_PER_TOKEN = 4.0;

    /** Characters per token for structured content (JSON, code). */
    private const JSON_CHARS_PER_TOKEN = 3.5;

    /**
     * Fallback context window sizes (in tokens) for models without a
     * `context_window` on their llm_pricing row. Anthropic models are all
     * priced there, so they are not repeated here.
     */
    private const MODEL_CONTEXT_LIMITS = [
        // OpenAI
        'gpt-4o' => 128_000,
        'gpt-4o-mini' => 128_000,
        // Google
        'gemini-2.5-flash' => 1_048_576,
        'gemini-2.5-pro' => 1_048_576,
    ];

    /** Default context limit when model is unknown. */
    private const DEFAULT_CONTEXT_LIMIT = 128_000;

    /**
     * Estimate token count for a given text string.
     */
    public function estimate(string $text, bool $isStructured = false): int
    {
        if ($text === '') {
            return 0;
        }

        $ratio = $isStructured ? self::JSON_CHARS_PER_TOKEN : self::CHARS_PER_TOKEN;

        return (int) ceil(mb_strlen($text) / $ratio);
    }

    /**
     * Estimate total tokens for a system prompt + user prompt pair.
     */
    public function estimateRequest(string $systemPrompt, string $userPrompt): int
    {
        // Add ~4 tokens overhead per message boundary (role markers)
        return $this->estimate($systemPrompt) + $this->estimate($userPrompt) + 8;
    }

    /**
     * Get the context window limit for a model.
     */
    public function getModelContextLimit(string $model): int
    {
        // The per-model pricing row is the source of truth (same lookup as
        // ContextHealthService). Array access: ids such as gemini-2.5-pro contain dots.
        foreach (config('llm_pricing.providers', []) as $rows) {
            if (isset($rows[$model]['context_window'])) {
                return (int) $rows[$model]['context_window'];
            }
        }

        // Try exact match first
        if (isset(self::MODEL_CONTEXT_LIMITS[$model])) {
            return self::MODEL_CONTEXT_LIMITS[$model];
        }

        // Try prefix match (e.g., "claude-sonnet-4-5" matches "claude-sonnet-4-5-20250929")
        foreach (self::MODEL_CONTEXT_LIMITS as $key => $limit) {
            if (str_starts_with($model, $key) || str_starts_with($key, $model)) {
                return $limit;
            }
        }

        return (int) config('context_compaction.default_context_limit', self::DEFAULT_CONTEXT_LIMIT);
    }

    /**
     * Calculate context utilization as a fraction (0.0 - 1.0+).
     */
    public function calculateUtilization(string $systemPrompt, string $userPrompt, string $model): float
    {
        $estimatedTokens = $this->estimateRequest($systemPrompt, $userPrompt);
        $contextLimit = $this->getModelContextLimit($model);

        if ($contextLimit <= 0) {
            return 0.0;
        }

        return $estimatedTokens / $contextLimit;
    }
}
