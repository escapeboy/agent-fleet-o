<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    |
    | Which entry in `drivers` the eval harness uses when --driver is omitted.
    |
    */

    'default' => env('DECISION_DRIVER', 'jev'),

    /*
    |--------------------------------------------------------------------------
    | Team the eval borrows
    |--------------------------------------------------------------------------
    |
    | LLM drivers go through the AI gateway, which logs every call to the
    | tenant-scoped llm_request_logs table. An eval has no tenant of its own, so
    | it borrows one. Overridden per run by `jev:eval --team=`.
    |
    */

    'team_id' => env('DECISION_TEAM_ID'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | `system_one` drivers speak the POST /v1/systemone wire format. `llm`
    | drivers run the same questions through the platform AI gateway with
    | structured output, so an ordinary chat model can be scored on the same
    | dataset with the same code.
    |
    | Keys come from the environment only. Nothing here may hold a literal
    | secret: TYPESAFE_API_KEY is resolved by `op run --env-file=.env.op`.
    |
    | Pin an exact model version, never an alias — `jev-latest` moves when a
    | release ships and silently invalidates every confidence threshold tuned
    | against the previous one.
    |
    */

    'drivers' => [

        'jev' => [
            'type' => 'system_one',
            'base_url' => env('TYPESAFE_BASE_URL', 'https://api.typesafe.ai'),
            'key' => env('TYPESAFE_API_KEY'),
            'model' => env('TYPESAFE_MODEL', 'jev-1.13.0'),
            'timeout' => (float) env('TYPESAFE_TIMEOUT', 5.0),
            'retries' => (int) env('TYPESAFE_RETRIES', 3),
        ],

        'jeff' => [
            'type' => 'system_one',
            'base_url' => env('JEFF_BASE_URL'),
            'key' => env('JEFF_API_KEY'),
            'model' => env('JEFF_MODEL', 'jeff-1'),
            'timeout' => (float) env('JEFF_TIMEOUT', 5.0),
            'retries' => (int) env('JEFF_RETRIES', 3),
        ],

        'haiku' => [
            'type' => 'llm',
            'provider' => 'anthropic',
            // The AI gateway's own RateLimiting middleware caps anthropic at 60
            // requests per minute across the whole process, so an eval that runs
            // flat out starves everything else and fails half its own cases.
            'requests_per_minute' => (int) env('DECISION_ANTHROPIC_RPM', 25),
            'model' => env('DECISION_HAIKU_MODEL', 'claude-haiku-4-5'),
            'max_tokens' => 2048,
            'temperature' => 0.0,
        ],

        'sonnet' => [
            'type' => 'llm',
            'provider' => 'anthropic',
            // The AI gateway's own RateLimiting middleware caps anthropic at 60
            // requests per minute across the whole process, so an eval that runs
            // flat out starves everything else and fails half its own cases.
            'requests_per_minute' => (int) env('DECISION_ANTHROPIC_RPM', 25),
            'model' => env('DECISION_SONNET_MODEL', 'claude-sonnet-5'),
            'max_tokens' => 2048,
            'temperature' => 0.0,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Jev 1.13 accepts 64k tokens per request and 32k for the state plus the
    | single longest question. The eval rejects a case over the state limit
    | rather than truncating it — a truncated state is a different case, and
    | scoring it would quietly corrupt the accuracy number.
    |
    */

    'limits' => [
        'state_tokens' => (int) env('DECISION_STATE_TOKEN_LIMIT', 32_000),
        'requests_per_minute' => (int) env('DECISION_REQUESTS_PER_MINUTE', 1_100),
        'tokens_per_second' => (int) env('DECISION_TOKENS_PER_SECOND', 225_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | USD per million INPUT tokens, per driver. Jev charges for input only;
    | output tokens are free. Used for the cost-per-1k-decisions column.
    |
    */

    'pricing_usd_per_mtok' => [
        'jev' => (float) env('TYPESAFE_PRICE_PER_MTOK', 0.042),
        'jeff' => (float) env('JEFF_PRICE_PER_MTOK', 0.0),
        'haiku' => (float) env('DECISION_HAIKU_PRICE_PER_MTOK', 1.0),
        'sonnet' => (float) env('DECISION_SONNET_PRICE_PER_MTOK', 2.0),
    ],

];
