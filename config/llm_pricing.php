<?php

/**
 * LLM pricing in credits per 1K tokens.
 *
 * 1 credit = $0.001 USD (adjustable).
 * Pricing is approximate and should be updated as providers change rates.
 */
return [

    'credit_value_usd' => 0.001,

    'providers' => [

        'anthropic' => [
            'claude-sonnet-4-5-20250929' => [
                'input' => 30,   // $3.00/1M tokens
                'output' => 150, // $15.00/1M tokens
            ],
            'claude-haiku-4-5-20251001' => [
                'input' => 8,    // $0.80/1M tokens
                'output' => 40,  // $4.00/1M tokens
            ],
            'claude-opus-4-6' => [
                'input' => 150,  // $15.00/1M tokens
                'output' => 750, // $75.00/1M tokens
            ],
        ],

        'openai' => [
            'gpt-4o' => [
                'input' => 25,   // $2.50/1M tokens
                'output' => 100, // $10.00/1M tokens
            ],
            'gpt-4o-mini' => [
                'input' => 2,    // $0.15/1M tokens
                'output' => 6,   // $0.60/1M tokens
            ],
        ],

        'google' => [
            'gemini-2.5-flash' => [
                'input' => 1,    // $0.075/1M tokens
                'output' => 3,   // $0.30/1M tokens
            ],
            'gemini-2.5-pro' => [
                'input' => 12,   // $1.25/1M tokens
                'output' => 50,  // $5.00/1M tokens
            ],
        ],

        'codex' => [
            'gpt-5.3-codex' => ['input' => 0, 'output' => 0],
            'gpt-5.2-codex' => ['input' => 0, 'output' => 0],
            'gpt-5.1-codex-mini' => ['input' => 0, 'output' => 0],
        ],

        'claude-code' => [
            'claude-sonnet-4-5' => ['input' => 0, 'output' => 0],
            'claude-opus-4-6' => ['input' => 0, 'output' => 0],
            'claude-haiku-4-5' => ['input' => 0, 'output' => 0],
        ],

        'groq' => [
            'llama-3.3-70b-versatile' => ['input' => 6, 'output' => 8],   // $0.59/$0.79 per 1M
            'llama-3.1-8b-instant' => ['input' => 1, 'output' => 1],      // $0.05/$0.08 per 1M
            'llama-4-scout-17b-16e' => ['input' => 1, 'output' => 3],     // $0.11/$0.34 per 1M
            'gemma2-9b-it' => ['input' => 2, 'output' => 2],              // $0.20/$0.20 per 1M
            'qwen-qwq-32b' => ['input' => 3, 'output' => 4],             // $0.29/$0.39 per 1M
            'mixtral-8x7b-32768' => ['input' => 2, 'output' => 2],        // $0.24/$0.24 per 1M
        ],

        // OpenRouter free models — zero cost (rate-limited by OpenRouter).
        'openrouter' => [],

        'mistral' => [
            'mistral-large-latest' => ['input' => 20,   'output' => 60],   // $2.00/$6.00 per 1M
            'mistral-small-latest' => ['input' => 1,    'output' => 3],    // $0.10/$0.30 per 1M
            'codestral-latest' => ['input' => 2,    'output' => 6],    // $0.20/$0.60 per 1M
            'mistral-nemo-latest' => ['input' => 2,    'output' => 2],    // $0.15/$0.15 per 1M
        ],

        'deepseek' => [
            'deepseek-chat' => ['input' => 3,    'output' => 11],   // $0.27/$1.10 per 1M
            'deepseek-reasoner' => ['input' => 6,    'output' => 22],   // $0.55/$2.19 per 1M
        ],

        'xai' => [
            'grok-3' => ['input' => 30,   'output' => 150],  // $3.00/$15.00 per 1M
            'grok-3-mini' => ['input' => 3,    'output' => 5],    // $0.30/$0.50 per 1M
            'grok-2-latest' => ['input' => 20,   'output' => 100],  // $2.00/$10.00 per 1M
        ],

        'perplexity' => [
            'sonar-pro' => ['input' => 30,   'output' => 150],  // $3.00/$15.00 per 1M
            'sonar' => ['input' => 10,   'output' => 10],   // $1.00/$1.00 per 1M
            'sonar-reasoning' => ['input' => 10,   'output' => 50],   // $1.00/$5.00 per 1M
        ],

        'fireworks' => [
            'accounts/fireworks/models/llama-v3p3-70b-instruct' => ['input' => 9,  'output' => 9],    // $0.90/$0.90 per 1M
            'accounts/fireworks/models/deepseek-r1' => ['input' => 30, 'output' => 80],   // $3.00/$8.00 per 1M
            'accounts/fireworks/models/qwen3-235b-a22b' => ['input' => 2,  'output' => 9],    // $0.22/$0.88 per 1M
            'accounts/fireworks/models/mixtral-8x22b-instruct' => ['input' => 12, 'output' => 12],   // $1.20/$1.20 per 1M
        ],

        // Local HTTP LLM providers — zero cost (runs on your hardware).
        // CostCalculator returns 0 for unknown models, so no per-model entries needed.
        // Add specific model entries here if you want explicit cost tracking.
        'ollama' => [],
        'openai_compatible' => [],
        'litellm_proxy' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Window Sizes (tokens)
    |--------------------------------------------------------------------------
    | Used by ContextHealthService to compute what fraction of a model's
    | context window an experiment has consumed across all its LLM calls.
    | Falls back to 200_000 for unknown models.
    */
    'context_windows' => [
        // Anthropic
        'claude-sonnet-4-5-20250929' => 200_000,
        'claude-haiku-4-5-20251001' => 200_000,
        'claude-opus-4-6' => 200_000,
        // OpenAI
        'gpt-4o' => 128_000,
        'gpt-4o-mini' => 128_000,
        // Google
        'gemini-2.5-flash' => 1_048_576,
        'gemini-2.5-pro' => 1_048_576,
        // Groq (various Llama models)
        'llama-3.3-70b-versatile' => 128_000,
        'llama-3.1-8b-instant' => 128_000,
        // Mistral
        'mistral-large-latest' => 128_000,
        'mistral-small-latest' => 32_000,
    ],

    // Default estimation multiplier for budget reservation
    // Reserve 1.5x the estimated cost to account for retries
    'reservation_multiplier' => 1.5,

    // Maximum tokens per request (safety limit)
    'max_output_tokens' => 8192,
];
