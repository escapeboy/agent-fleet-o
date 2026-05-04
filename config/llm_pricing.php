<?php

/**
 * LLM pricing — source of truth for cost & credit calculation.
 *
 * Two distinct credit units coexist:
 *   - cost_credit:    1 cost_credit  = $0.001 USD (raw provider COGS, used by CostCalculator::calculateCost back-compat)
 *   - platform_credit: 1 platform_credit = $0.01 USD by default (customer-facing billing unit, includes margin)
 *
 * `last_verified_at` flags pricing entries — admin alerts fire if not refreshed in N days (P1).
 * Snapshot of this entire structure is persisted to llm_pricing_snapshots on every change for audit.
 */
return [

    // 1 cost_credit = $0.001 USD. Anchor for back-compat calculateCost() call sites.
    'credit_value_usd' => 0.001,

    // 1 platform_credit price (customer-facing). Customer pays FleetQ per-credit at this rate.
    'usd_per_credit' => (float) env('FLEETQ_USD_PER_CREDIT', 0.01),

    // Default margin layer applied between raw provider cost and billable platform_credits.
    // Per-team override via teams.credit_margin_multiplier. Per-call override via $marginOverride arg.
    'margin_multiplier' => (float) env('FLEETQ_MARGIN_MULTIPLIER', 1.30),

    // Min platform_credits per call — protects margin on nano models (€0.0005 raw → 1 credit charged).
    'min_credits_per_call' => (int) env('FLEETQ_MIN_CREDITS_PER_CALL', 1),

    // Optional safety cap. Per-team override via teams.max_credits_per_call. null = uncapped.
    'max_credits_per_call' => env('FLEETQ_MAX_CREDITS_PER_CALL') !== null
        ? (int) env('FLEETQ_MAX_CREDITS_PER_CALL')
        : null,

    // BYOK platform fee — flat platform_credits charged per LLM call when team uses
    // their own provider key. Default 0 preserves current "BYOK skips deduction" behavior.
    // Per-team override via teams.byok_platform_fee_per_call.
    'byok_platform_fee_per_call' => (int) env('FLEETQ_BYOK_PLATFORM_FEE', 0),

    // Cost-alert thresholds for EvaluateCostAlertsCommand (P1-3).
    'alerts' => [
        'bleeding_team_ratio' => (float) env('FLEETQ_ALERT_BLEEDING_RATIO', 1.5),
        'stale_pricing_days' => (int) env('FLEETQ_ALERT_STALE_DAYS', 90),
        'margin_drift_threshold_pct' => (float) env('FLEETQ_ALERT_MARGIN_DRIFT', 25),
    ],

    // Helicone auto-sync drift threshold (fraction). >this triggers snapshot rotation. (P1-1)
    'sync_drift_threshold' => (float) env('FLEETQ_PRICING_DRIFT_THRESHOLD', 0.05),

    'usd_to_eur_rate' => (float) env('FLEETQ_USD_TO_EUR', 0.91),

    'last_updated_at' => '2026-05-04',

    /*
    |--------------------------------------------------------------------------
    | Reservation multipliers (by tier)
    |--------------------------------------------------------------------------
    |
    | Used by CostCalculator::estimatePlatformCredits() and BudgetEnforcement
    | reservation. Different model classes have different output-length variance:
    | nano calls converge tightly (1.2x); reasoning/heavy calls vary widely (2.0x).
    |
    */
    'reservation_multipliers' => [
        'default' => (float) env('FLEETQ_RESERVATION_DEFAULT', 1.5),
        'nano' => (float) env('FLEETQ_RESERVATION_NANO', 1.2),
        'heavy' => (float) env('FLEETQ_RESERVATION_HEAVY', 2.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-provider / per-model pricing
    |--------------------------------------------------------------------------
    |
    | Schema (all rates in $USD/Mtok unless noted):
    |   tier:                          'default'|'nano'|'heavy' — selects reservation multiplier
    |   input_usd_per_mtok:            standard input
    |   output_usd_per_mtok:           generation output
    |   cache_read_usd_per_mtok:       Anthropic prompt-caching read (reused tokens)
    |   cache_write_5m_usd_per_mtok:   ephemeral 5-minute cache write surcharge
    |   cache_write_1h_usd_per_mtok:   ephemeral 1-hour cache write surcharge
    |   context_window:                tokens (used by ContextHealthService)
    |   last_verified_at:              ISO date — staleness alerts fire if older than 90 days (P1)
    |   source_url:                    where rates were sourced (audit trail)
    |
    */
    'providers' => [

        'anthropic' => [
            'claude-opus-4-7' => [
                'tier' => 'heavy',
                'input_usd_per_mtok' => 5.00,
                'output_usd_per_mtok' => 25.00,
                'cache_read_usd_per_mtok' => 0.50,
                'cache_write_5m_usd_per_mtok' => 6.25,
                'cache_write_1h_usd_per_mtok' => 10.00,
                'context_window' => 200_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://docs.claude.com/en/docs/about-claude/pricing',
            ],
            'claude-opus-4-6' => [
                'tier' => 'heavy',
                'input_usd_per_mtok' => 15.00,
                'output_usd_per_mtok' => 75.00,
                'cache_read_usd_per_mtok' => 1.50,
                'cache_write_5m_usd_per_mtok' => 18.75,
                'cache_write_1h_usd_per_mtok' => 30.00,
                'context_window' => 200_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://docs.claude.com/en/docs/about-claude/pricing',
            ],
            'claude-sonnet-4-6' => [
                'tier' => 'default',
                'input_usd_per_mtok' => 3.00,
                'output_usd_per_mtok' => 15.00,
                'cache_read_usd_per_mtok' => 0.30,
                'cache_write_5m_usd_per_mtok' => 3.75,
                'cache_write_1h_usd_per_mtok' => 6.00,
                'context_window' => 200_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://docs.claude.com/en/docs/about-claude/pricing',
            ],
            'claude-sonnet-4-5' => [
                'tier' => 'default',
                'input_usd_per_mtok' => 3.00,
                'output_usd_per_mtok' => 15.00,
                'cache_read_usd_per_mtok' => 0.30,
                'cache_write_5m_usd_per_mtok' => 3.75,
                'cache_write_1h_usd_per_mtok' => 6.00,
                'context_window' => 200_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://docs.claude.com/en/docs/about-claude/pricing',
            ],
            'claude-sonnet-4-5-20250929' => [
                'tier' => 'default',
                'input_usd_per_mtok' => 3.00,
                'output_usd_per_mtok' => 15.00,
                'cache_read_usd_per_mtok' => 0.30,
                'cache_write_5m_usd_per_mtok' => 3.75,
                'cache_write_1h_usd_per_mtok' => 6.00,
                'context_window' => 200_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://docs.claude.com/en/docs/about-claude/pricing',
            ],
            'claude-haiku-4-5' => [
                'tier' => 'nano',
                'input_usd_per_mtok' => 1.00,
                'output_usd_per_mtok' => 5.00,
                'cache_read_usd_per_mtok' => 0.10,
                'cache_write_5m_usd_per_mtok' => 1.25,
                'cache_write_1h_usd_per_mtok' => 2.00,
                'context_window' => 200_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://docs.claude.com/en/docs/about-claude/pricing',
            ],
            'claude-haiku-4-5-20251001' => [
                'tier' => 'nano',
                'input_usd_per_mtok' => 1.00,
                'output_usd_per_mtok' => 5.00,
                'cache_read_usd_per_mtok' => 0.10,
                'cache_write_5m_usd_per_mtok' => 1.25,
                'cache_write_1h_usd_per_mtok' => 2.00,
                'context_window' => 200_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://docs.claude.com/en/docs/about-claude/pricing',
            ],
        ],

        'openai' => [
            'gpt-5' => [
                'tier' => 'default',
                'input_usd_per_mtok' => 1.25,
                'output_usd_per_mtok' => 10.00,
                'cache_read_usd_per_mtok' => 0.13,
                'context_window' => 400_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://platform.openai.com/docs/pricing',
            ],
            'gpt-5-nano' => [
                'tier' => 'nano',
                'input_usd_per_mtok' => 0.05,
                'output_usd_per_mtok' => 0.40,
                'cache_read_usd_per_mtok' => 0.005,
                'context_window' => 128_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://platform.openai.com/docs/pricing',
            ],
            'gpt-4o' => [
                'tier' => 'default',
                'input_usd_per_mtok' => 2.50,
                'output_usd_per_mtok' => 10.00,
                'cache_read_usd_per_mtok' => 1.25,
                'context_window' => 128_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://platform.openai.com/docs/pricing',
            ],
            'gpt-4o-mini' => [
                'tier' => 'nano',
                'input_usd_per_mtok' => 0.15,
                'output_usd_per_mtok' => 0.60,
                'cache_read_usd_per_mtok' => 0.075,
                'context_window' => 128_000,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://platform.openai.com/docs/pricing',
            ],
        ],

        'google' => [
            'gemini-2.5-flash' => [
                'tier' => 'nano',
                'input_usd_per_mtok' => 0.30,
                'output_usd_per_mtok' => 2.50,
                'context_window' => 1_048_576,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://ai.google.dev/pricing',
            ],
            'gemini-2.5-pro' => [
                'tier' => 'default',
                'input_usd_per_mtok' => 1.25,
                'output_usd_per_mtok' => 5.00,
                'context_window' => 1_048_576,
                'last_verified_at' => '2026-05-04',
                'source_url' => 'https://ai.google.dev/pricing',
            ],
        ],

        'groq' => [
            'llama-3.3-70b-versatile' => ['tier' => 'default', 'input_usd_per_mtok' => 0.59, 'output_usd_per_mtok' => 0.79, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
            'llama-3.1-8b-instant' => ['tier' => 'nano', 'input_usd_per_mtok' => 0.05, 'output_usd_per_mtok' => 0.08, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
            'llama-4-scout-17b-16e' => ['tier' => 'default', 'input_usd_per_mtok' => 0.11, 'output_usd_per_mtok' => 0.34, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
            'gemma2-9b-it' => ['tier' => 'nano', 'input_usd_per_mtok' => 0.20, 'output_usd_per_mtok' => 0.20, 'context_window' => 8_192, 'last_verified_at' => '2026-05-04'],
            'qwen-qwq-32b' => ['tier' => 'heavy', 'input_usd_per_mtok' => 0.29, 'output_usd_per_mtok' => 0.39, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
            'mixtral-8x7b-32768' => ['tier' => 'default', 'input_usd_per_mtok' => 0.24, 'output_usd_per_mtok' => 0.24, 'context_window' => 32_768, 'last_verified_at' => '2026-05-04'],
        ],

        'mistral' => [
            'mistral-large-latest' => ['tier' => 'default', 'input_usd_per_mtok' => 2.00, 'output_usd_per_mtok' => 6.00, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
            'mistral-small-latest' => ['tier' => 'nano', 'input_usd_per_mtok' => 0.10, 'output_usd_per_mtok' => 0.30, 'context_window' => 32_000, 'last_verified_at' => '2026-05-04'],
            'codestral-latest' => ['tier' => 'default', 'input_usd_per_mtok' => 0.20, 'output_usd_per_mtok' => 0.60, 'context_window' => 32_000, 'last_verified_at' => '2026-05-04'],
            'mistral-nemo-latest' => ['tier' => 'nano', 'input_usd_per_mtok' => 0.15, 'output_usd_per_mtok' => 0.15, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
        ],

        'deepseek' => [
            'deepseek-chat' => ['tier' => 'default', 'input_usd_per_mtok' => 0.27, 'output_usd_per_mtok' => 1.10, 'context_window' => 64_000, 'last_verified_at' => '2026-05-04'],
            'deepseek-reasoner' => ['tier' => 'heavy', 'input_usd_per_mtok' => 0.55, 'output_usd_per_mtok' => 2.19, 'context_window' => 64_000, 'last_verified_at' => '2026-05-04'],
        ],

        'xai' => [
            'grok-3' => ['tier' => 'default', 'input_usd_per_mtok' => 3.00, 'output_usd_per_mtok' => 15.00, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
            'grok-3-mini' => ['tier' => 'nano', 'input_usd_per_mtok' => 0.30, 'output_usd_per_mtok' => 0.50, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
            'grok-2-latest' => ['tier' => 'default', 'input_usd_per_mtok' => 2.00, 'output_usd_per_mtok' => 10.00, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
        ],

        'perplexity' => [
            'sonar-pro' => ['tier' => 'default', 'input_usd_per_mtok' => 3.00, 'output_usd_per_mtok' => 15.00, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
            'sonar' => ['tier' => 'nano', 'input_usd_per_mtok' => 1.00, 'output_usd_per_mtok' => 1.00, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
            'sonar-reasoning' => ['tier' => 'heavy', 'input_usd_per_mtok' => 1.00, 'output_usd_per_mtok' => 5.00, 'context_window' => 128_000, 'last_verified_at' => '2026-05-04'],
        ],

        'fireworks' => [
            'accounts/fireworks/models/llama-v3p3-70b-instruct' => ['tier' => 'default', 'input_usd_per_mtok' => 0.90, 'output_usd_per_mtok' => 0.90, 'last_verified_at' => '2026-05-04'],
            'accounts/fireworks/models/deepseek-r1' => ['tier' => 'heavy', 'input_usd_per_mtok' => 3.00, 'output_usd_per_mtok' => 8.00, 'last_verified_at' => '2026-05-04'],
            'accounts/fireworks/models/qwen3-235b-a22b' => ['tier' => 'default', 'input_usd_per_mtok' => 0.22, 'output_usd_per_mtok' => 0.88, 'last_verified_at' => '2026-05-04'],
            'accounts/fireworks/models/mixtral-8x22b-instruct' => ['tier' => 'default', 'input_usd_per_mtok' => 1.20, 'output_usd_per_mtok' => 1.20, 'last_verified_at' => '2026-05-04'],
        ],

        // Passthrough / zero-cost providers — pricing tracked elsewhere or runs locally.
        'portkey' => [
            '*' => ['tier' => 'default', 'input_usd_per_mtok' => 0, 'output_usd_per_mtok' => 0],
        ],
        'codex' => [
            'gpt-5.3-codex' => ['tier' => 'default', 'input_usd_per_mtok' => 0, 'output_usd_per_mtok' => 0],
            'gpt-5.2-codex' => ['tier' => 'default', 'input_usd_per_mtok' => 0, 'output_usd_per_mtok' => 0],
            'gpt-5.1-codex-mini' => ['tier' => 'nano', 'input_usd_per_mtok' => 0, 'output_usd_per_mtok' => 0],
        ],
        'claude-code' => [
            'claude-sonnet-4-5' => ['tier' => 'default', 'input_usd_per_mtok' => 0, 'output_usd_per_mtok' => 0],
            'claude-opus-4-6' => ['tier' => 'heavy', 'input_usd_per_mtok' => 0, 'output_usd_per_mtok' => 0],
            'claude-haiku-4-5' => ['tier' => 'nano', 'input_usd_per_mtok' => 0, 'output_usd_per_mtok' => 0],
        ],
        'openrouter' => [],
        'ollama' => [],
        'openai_compatible' => [],
        'litellm_proxy' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Non-LLM cost categories (scaffolded, P0 cost = 0)
    |--------------------------------------------------------------------------
    |
    | Threaded through but NOT deducted yet. Real prices land in P1+ once
    | each subsystem reports usage events. Schema is stable — adding new keys
    | here doesn't break anything.
    |
    */
    'compute' => [
        'browser_session_minute' => ['usd_per_unit' => 0.00, 'last_verified_at' => '2026-05-04'],
        'code_execution_minute' => ['usd_per_unit' => 0.00, 'last_verified_at' => '2026-05-04'],
        'sandbox_gb_hour' => ['usd_per_unit' => 0.00, 'last_verified_at' => '2026-05-04'],
    ],
    'outbound' => [
        'email_send' => ['usd_per_unit' => 0.00, 'last_verified_at' => '2026-05-04'],
        'sms_send' => ['usd_per_unit' => 0.00, 'last_verified_at' => '2026-05-04'],
    ],
    'storage' => [
        'artifact_gb_month' => ['usd_per_unit' => 0.00, 'last_verified_at' => '2026-05-04'],
    ],
    'tool' => [
        'mcp_call' => ['usd_per_unit' => 0.00, 'last_verified_at' => '2026-05-04'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Window Sizes (legacy lookup — back-compat for ContextHealthService)
    |--------------------------------------------------------------------------
    |
    | Now sourced from per-model `context_window` field above. This map kept for
    | callers using the old `config('llm_pricing.context_windows.{model}')` path.
    | Will be removed once all callers migrate to per-provider lookup.
    |
    */
    'context_windows' => [
        'claude-sonnet-4-5-20250929' => 200_000,
        'claude-haiku-4-5-20251001' => 200_000,
        'claude-opus-4-6' => 200_000,
        'claude-opus-4-7' => 200_000,
        'gpt-4o' => 128_000,
        'gpt-4o-mini' => 128_000,
        'gpt-5' => 400_000,
        'gpt-5-nano' => 128_000,
        'gemini-2.5-flash' => 1_048_576,
        'gemini-2.5-pro' => 1_048_576,
        'llama-3.3-70b-versatile' => 128_000,
        'llama-3.1-8b-instant' => 128_000,
        'mistral-large-latest' => 128_000,
        'mistral-small-latest' => 32_000,
    ],

    // Default reservation multiplier for budget reservation (back-compat).
    // CostCalculator::estimateCost() falls back to this when tier-specific not found.
    'reservation_multiplier' => 1.5,

    // Maximum tokens per request (safety limit)
    'max_output_tokens' => 8192,
];
