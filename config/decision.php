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

            // BYOK: the `provider` value a team stores on TeamProviderCredential
            // to run this driver on its own key instead of the platform's.
            'credential_provider' => 'typesafe',

            // Charged only when the call runs on the PLATFORM key. Flat per
            // call, not derived from tokens: the wire format reports input
            // tokens and no output tokens, so there is no output price to
            // derive. 1 credit = $0.001.
            'credits_per_call' => (int) env('TYPESAFE_CREDITS_PER_CALL', 2),
        ],

        'jeff' => [
            'type' => 'system_one',
            'base_url' => env('JEFF_BASE_URL'),
            'key' => env('JEFF_API_KEY'),
            'model' => env('JEFF_MODEL', 'jeff-1'),
            'timeout' => (float) env('JEFF_TIMEOUT', 5.0),
            'retries' => (int) env('JEFF_RETRIES', 3),
            'credential_provider' => 'jeff',
            'credits_per_call' => (int) env('JEFF_CREDITS_PER_CALL', 2),
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

        // Local Claude Code CLI. Runs on the CLI's own credentials (a
        // subscription), so it needs no API key and no team — it never touches
        // the gateway. Used when the metered Anthropic key is unavailable.
        'claude_cli_haiku' => [
            'type' => 'cli',
            'binary' => env('CLAUDE_CLI_BINARY', 'claude'),
            'model' => env('DECISION_CLI_HAIKU_MODEL', 'claude-haiku-4-5'),
            'timeout' => (int) env('CLAUDE_CLI_TIMEOUT', 180),
            'concurrency' => (int) env('CLAUDE_CLI_CONCURRENCY', 4),
        ],

        'claude_cli_sonnet' => [
            'type' => 'cli',
            'binary' => env('CLAUDE_CLI_BINARY', 'claude'),
            'model' => env('DECISION_CLI_SONNET_MODEL', 'claude-sonnet-5'),
            'timeout' => (int) env('CLAUDE_CLI_TIMEOUT', 180),
            'concurrency' => (int) env('CLAUDE_CLI_CONCURRENCY', 4),
        ],

        // Cross-family baselines. Neither Google nor OpenAI produced the gold
        // labels of any dataset in this harness, so they score the questions
        // without the co-authorship advantage anthropic models carry on the
        // ploshtad topics sets.
        'gemini-flash' => [
            'type' => 'llm',
            'provider' => 'google',
            'requests_per_minute' => (int) env('DECISION_GOOGLE_RPM', 50),
            'model' => env('DECISION_GEMINI_MODEL', 'gemini-3.6-flash'),
            'max_tokens' => 2048,
            'temperature' => 0.0,
        ],

        'gpt-mini' => [
            'type' => 'llm',
            'provider' => 'openai',
            'requests_per_minute' => (int) env('DECISION_OPENAI_RPM', 60),
            'model' => env('DECISION_GPT_MINI_MODEL', 'gpt-4o-mini'),
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
        // List price of the same call. The CLI bills a subscription, so this is
        // what the run WOULD have cost on the metered API, not what it did.
        'claude_cli_haiku' => (float) env('DECISION_HAIKU_PRICE_PER_MTOK', 1.0),
        'claude_cli_sonnet' => (float) env('DECISION_SONNET_PRICE_PER_MTOK', 2.0),
        'sonnet' => (float) env('DECISION_SONNET_PRICE_PER_MTOK', 2.0),
        // Estimate: the 2.5-flash list rate. 3.6-flash is not in llm_pricing.php,
        // so the gemini cost column is indicative, not billed truth.
        'gemini-flash' => (float) env('DECISION_GEMINI_PRICE_PER_MTOK', 0.30),
        'gpt-mini' => (float) env('DECISION_GPT_MINI_PRICE_PER_MTOK', 0.15),
    ],

];
