<?php

return [
    'anthropic' => [
        'name' => 'Anthropic',
        'models' => [
            'claude-sonnet-4-5' => ['label' => 'Claude Sonnet 4.5', 'input_cost' => 3, 'output_cost' => 15],
            'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5', 'input_cost' => 1, 'output_cost' => 5],
            'claude-opus-4-6' => ['label' => 'Claude Opus 4.6', 'input_cost' => 15, 'output_cost' => 75],
        ],
    ],
    'openai' => [
        'name' => 'OpenAI',
        'models' => [
            'gpt-4o' => ['label' => 'GPT-4o', 'input_cost' => 5, 'output_cost' => 15],
            'gpt-4o-mini' => ['label' => 'GPT-4o Mini', 'input_cost' => 0.15, 'output_cost' => 0.60],
        ],
    ],
    'google' => [
        'name' => 'Google',
        'models' => [
            'gemini-2.5-flash' => ['label' => 'Gemini 2.5 Flash', 'input_cost' => 0.5, 'output_cost' => 1.5],
            'gemini-2.5-pro' => ['label' => 'Gemini 2.5 Pro', 'input_cost' => 7, 'output_cost' => 21],
        ],
    ],
    'local' => [
        'name' => 'Local Agents',
        'models' => [
            'codex' => ['label' => 'OpenAI Codex (local)', 'input_cost' => 0, 'output_cost' => 0],
            'claude-code' => ['label' => 'Claude Code (local)', 'input_cost' => 0, 'output_cost' => 0],
        ],
    ],
];
