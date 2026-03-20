<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Context Compaction
    |--------------------------------------------------------------------------
    |
    | Token-aware context compaction middleware for the AI Gateway.
    | Automatically compresses conversation context when it approaches the
    | model's token limit, using a 4-stage escalation pipeline.
    |
    */

    'enabled' => env('CONTEXT_COMPACTION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Compaction Thresholds (fraction of model context window)
    |--------------------------------------------------------------------------
    |
    | summarize_threshold: Stage 1 (tool output) + Stage 2 (LLM summarization)
    | window_threshold:    Stage 3 (sliding window — drop old turns)
    | emergency_threshold: Stage 4 (hard truncation)
    | target_utilization:  Drain line — compact down to this level to prevent thrashing
    |
    */

    'summarize_threshold' => (float) env('CONTEXT_COMPACTION_SUMMARIZE', 0.70),
    'window_threshold' => (float) env('CONTEXT_COMPACTION_WINDOW', 0.85),
    'emergency_threshold' => (float) env('CONTEXT_COMPACTION_EMERGENCY', 0.92),
    'target_utilization' => (float) env('CONTEXT_COMPACTION_TARGET', 0.55),

    /*
    |--------------------------------------------------------------------------
    | Preserved Turns
    |--------------------------------------------------------------------------
    |
    | Minimum number of recent conversation turns to always preserve.
    | These are never summarized or truncated (multiplied by 3 for line count).
    |
    */

    'min_preserved_turns' => (int) env('CONTEXT_COMPACTION_PRESERVED_TURNS', 4),

    /*
    |--------------------------------------------------------------------------
    | Summarizer Model
    |--------------------------------------------------------------------------
    |
    | The cheap/fast model used for summarizing older conversation history.
    | Format: "provider/model" (e.g., "anthropic/claude-haiku-4-5").
    |
    */

    'summarizer_model' => env('CONTEXT_COMPACTION_SUMMARIZER', 'anthropic/claude-haiku-4-5'),
    'summarizer_max_tokens' => (int) env('CONTEXT_COMPACTION_SUMMARIZER_TOKENS', 2000),

    /*
    |--------------------------------------------------------------------------
    | Default Context Limit
    |--------------------------------------------------------------------------
    |
    | Fallback context window size (in tokens) for unknown models.
    |
    */

    'default_context_limit' => (int) env('CONTEXT_COMPACTION_DEFAULT_LIMIT', 128000),

];
