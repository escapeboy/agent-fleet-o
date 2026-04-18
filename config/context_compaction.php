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
    | Summarizer API Key
    |--------------------------------------------------------------------------
    |
    | Optional dedicated API key for the conversation compactor's LLM synthesis
    | step. When set, this key is used instead of the platform's provider key.
    | Useful when teams use BYOK and there is no platform-level provider key.
    |
    | Example: CONTEXT_COMPACTION_ANTHROPIC_API_KEY=sk-ant-api03-...
    |
    */

    'summarizer_api_key' => env('CONTEXT_COMPACTION_ANTHROPIC_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Default Context Limit
    |--------------------------------------------------------------------------
    |
    | Fallback context window size (in tokens) for unknown models.
    |
    */

    'default_context_limit' => (int) env('CONTEXT_COMPACTION_DEFAULT_LIMIT', 128000),

    /*
    |--------------------------------------------------------------------------
    | Assistant Conversation Compaction Threshold
    |--------------------------------------------------------------------------
    |
    | Number of active (non-archived) messages in an AssistantConversation
    | before ConversationCompactor creates a pinned summary snapshot.
    | Older messages are archived (kept in DB, excluded from context builds).
    |
    */

    'compaction_message_threshold' => (int) env('CONTEXT_COMPACTION_MESSAGE_THRESHOLD', 40),

    /*
    |--------------------------------------------------------------------------
    | Tool Throttle
    |--------------------------------------------------------------------------
    |
    | When enabled, per-conversation tool call counts are tracked in Redis and
    | a <tool_budget> hint is injected into the assistant system prompt when
    | any tool approaches its configured warn threshold.
    |
    */

    'tool_throttle_enabled' => env('CONTEXT_TOOL_THROTTLE_ENABLED', true),

];
