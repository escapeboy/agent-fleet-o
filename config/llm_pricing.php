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
    ],

    // Default estimation multiplier for budget reservation
    // Reserve 1.5x the estimated cost to account for retries
    'reservation_multiplier' => 1.5,

    // Maximum tokens per request (safety limit)
    'max_output_tokens' => 8192,
];
