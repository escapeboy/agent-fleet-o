<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Complexity Classification Thresholds
    |--------------------------------------------------------------------------
    |
    | Heuristic thresholds for classifying AI requests as light/standard/heavy.
    | Adjust these to tune when the system upgrades to a more capable model.
    |
    */
    'complexity_thresholds' => [
        'tool_count' => [
            'standard' => 3,
            'heavy' => 11,
        ],
        'max_tokens' => [
            'standard' => 1024,
            'heavy' => 4096,
        ],
        'prompt_tokens' => [
            'standard' => 2000,
            'heavy' => 8000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget Pressure Thresholds
    |--------------------------------------------------------------------------
    |
    | Percentage of monthly budget consumed before triggering downgrades.
    | At 'low', heavy requests downgrade to standard.
    | At 'medium', standard also downgrades to light where safe.
    | At 'high', all requests route to the cheapest model.
    |
    */
    'budget_pressure' => [
        'enabled' => (bool) env('AI_BUDGET_PRESSURE_ENABLED', true),
        'thresholds' => [
            'low' => 50,
            'medium' => 75,
            'high' => 90,
        ],
        // Requests with this many tools are never downgraded below standard
        'min_tools_for_standard' => 5,
        // Output-verbosity directive injected alongside the model downgrade:
        // under pressure >= min_level, the agent is told to minimize output tokens.
        // Stretches a fixed credit pool by trimming response length, not just model cost.
        'concise_directive' => [
            'enabled' => (bool) env('AI_BUDGET_CONCISE_ENABLED', true),
            'min_level' => env('AI_BUDGET_CONCISE_MIN_LEVEL', 'medium'), // low|medium|high
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Escalation
    |--------------------------------------------------------------------------
    |
    | When an AI call fails due to quality issues, retry with a stronger model
    | before falling back to another provider. Max escalation attempts limits
    | how many tiers up we try (light→standard→heavy = 2 max).
    |
    */
    'escalation' => [
        'enabled' => (bool) env('AI_ESCALATION_ENABLED', true),
        'max_attempts' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Gate
    |--------------------------------------------------------------------------
    |
    | After each experiment pipeline stage, run mechanical verification on the
    | output. On failure, inject error context and retry within the same job.
    |
    */
    'verification' => [
        'enabled' => (bool) env('AI_VERIFICATION_ENABLED', true),
        'max_retries' => 2,
        'timeout_warning_seconds' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Structured Output Self-Correction
    |--------------------------------------------------------------------------
    |
    | Prism validates structured (schema) output natively, so first-class
    | providers rarely return an unparseable result. The exception is
    | custom_endpoint / self-hosted models (Ollama-style) that ignore native
    | JSON-schema and emit prose or fenced JSON, leaving parsedOutput null.
    |
    | When enabled, SchemaValidation re-prompts up to `max_attempts` times for a
    | single valid JSON object before giving up. Each retry is a fresh, metered
    | provider call (downstream of this middleware — not budget/idempotency
    | re-checked), so keep max_attempts small. Disabled by default; activate via
    | env flag.
    |
    */
    'structured_self_correction' => [
        'enabled' => (bool) env('AI_STRUCTURED_SELF_CORRECTION_ENABLED', false),
        'max_attempts' => (int) env('AI_STRUCTURED_SELF_CORRECTION_MAX_ATTEMPTS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shadow Traffic
    |--------------------------------------------------------------------------
    |
    | Sampled, fire-and-forget A/B: after a primary completion the SAME prompt is
    | mirrored to a candidate model on a background queue and both results
    | (cost, latency, output hash) are recorded — the shadow output is NEVER
    | served. The primary request path never awaits the shadow call. The shadow
    | call IS metered (real provider spend), so keep sample_rate low. Disabled by
    | default; only mirrors plain text generations (no tools / no structured).
    |
    */
    'shadow_traffic' => [
        'enabled' => (bool) env('AI_SHADOW_TRAFFIC_ENABLED', false),
        'sample_rate' => (float) env('AI_SHADOW_TRAFFIC_SAMPLE_RATE', 0.0),
        'provider' => env('AI_SHADOW_TRAFFIC_PROVIDER'),
        'model' => env('AI_SHADOW_TRAFFIC_MODEL'),
        'queue' => env('AI_SHADOW_TRAFFIC_QUEUE', 'metrics'),
        // Persist truncated output text for manual inspection (off — PII/storage).
        'store_snippets' => (bool) env('AI_SHADOW_TRAFFIC_STORE_SNIPPETS', false),
        'snippet_chars' => (int) env('AI_SHADOW_TRAFFIC_SNIPPET_CHARS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stuck Detection
    |--------------------------------------------------------------------------
    |
    | Sliding-window pattern analysis over recent state transitions to detect
    | loops, oscillations, and stalls. Runs via RecoverStuckTasks command.
    |
    */
    'stuck_detection' => [
        'enabled' => (bool) env('AI_STUCK_DETECTION_ENABLED', true),
        'window_size' => 10,
        'oscillation_threshold' => 3,
        'repeated_failure_threshold' => 3,
        'tool_loop_repetition_rate' => 0.70,
        'stall_multiplier' => 2.0,
        'budget_drain_multiplier' => 3.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic Fast Mode
    |--------------------------------------------------------------------------
    |
    | Opt-in per-request `anthropic-beta` header for Claude's Fast Mode
    | research preview. Requires the team's Anthropic account to have the
    | beta enabled. The identifier is env-configurable because Anthropic
    | occasionally rotates it; defaults to a known recent value.
    |
    | Requests get Fast Mode when any of:
    |   - AiRequestDTO::$fastMode is true (explicit caller opt-in)
    |   - $request->purpose starts with one of auto_enable_purpose_prefixes
    |
    | The global `enabled` switch is an env kill-switch — if the beta
    | identifier is invalid for your account, leave it off to avoid 400s.
    |
    */
    'fast_mode' => [
        'enabled' => (bool) env('ANTHROPIC_FAST_MODE_ENABLED', false),
        'beta_identifier' => env('ANTHROPIC_FAST_MODE_BETA', 'fast-2025-05-01'),
        'auto_enable_purpose_prefixes' => ['signal.', 'digest.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Ranking (health/latency-weighted routing)
    |--------------------------------------------------------------------------
    |
    | ProviderRanker reorders the cloud fallback chain by 24h-rolling metrics
    | (computed every 5 min by ComputeProviderRankingJob). A per-request
    | AiRequestDTO::$gatewaySort always wins; this is the platform DEFAULT applied
    | when a request doesn't specify one.
    |
    | Values: null (off — per-request only), 'cost', 'latency', or 'health'
    | ('health' = route to the fastest provider with the best recent success
    | rate). Activate fleet-wide by setting AI_PROVIDER_RANKING_SORT=health.
    |
    */
    'provider_ranking' => [
        'default_sort' => env('AI_PROVIDER_RANKING_SORT'),
    ],

];
