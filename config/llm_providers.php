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
    'codex' => [
        'name' => 'Codex (Local)',
        'local' => true,
        'agent_key' => 'codex',
        'models' => [
            'gpt-5.3-codex' => ['label' => 'GPT-5.3 Codex', 'input_cost' => 0, 'output_cost' => 0],
            'gpt-5.2-codex' => ['label' => 'GPT-5.2 Codex', 'input_cost' => 0, 'output_cost' => 0],
            'gpt-5.1-codex-mini' => ['label' => 'GPT-5.1 Codex Mini', 'input_cost' => 0, 'output_cost' => 0],
        ],
    ],
    'claude-code' => [
        'name' => 'Claude Code (Local)',
        'local' => true,
        'agent_key' => 'claude-code',
        'models' => [
            'claude-sonnet-4-5' => ['label' => 'Claude Sonnet 4.5', 'input_cost' => 0, 'output_cost' => 0],
            'claude-opus-4-6' => ['label' => 'Claude Opus 4.6', 'input_cost' => 0, 'output_cost' => 0],
            'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5', 'input_cost' => 0, 'output_cost' => 0],
        ],
    ],

    // HTTP-based local LLM providers (Ollama, OpenAI-compatible endpoints)
    'ollama' => [
        'name' => 'Ollama',
        'http_local' => true,
        'default_url' => 'http://localhost:11434',
        'url_hint' => 'Root URL without /v1 suffix — e.g. http://localhost:11434',
        'models' => [
            'llama3.3'          => ['label' => 'Llama 3.3 (70B)',         'input_cost' => 0, 'output_cost' => 0],
            'llama3.2'          => ['label' => 'Llama 3.2 (3B)',          'input_cost' => 0, 'output_cost' => 0],
            'llama3.1'          => ['label' => 'Llama 3.1 (8B)',          'input_cost' => 0, 'output_cost' => 0],
            'mistral'           => ['label' => 'Mistral 7B',              'input_cost' => 0, 'output_cost' => 0],
            'mistral-nemo'      => ['label' => 'Mistral Nemo (12B)',      'input_cost' => 0, 'output_cost' => 0],
            'qwen2.5'           => ['label' => 'Qwen 2.5 (7B)',           'input_cost' => 0, 'output_cost' => 0],
            'qwen2.5:14b'       => ['label' => 'Qwen 2.5 (14B)',          'input_cost' => 0, 'output_cost' => 0],
            'qwen2.5:32b'       => ['label' => 'Qwen 2.5 (32B)',          'input_cost' => 0, 'output_cost' => 0],
            'qwen2.5-coder'     => ['label' => 'Qwen 2.5 Coder (7B)',     'input_cost' => 0, 'output_cost' => 0],
            'gemma3'            => ['label' => 'Gemma 3 (4B)',            'input_cost' => 0, 'output_cost' => 0],
            'gemma3:12b'        => ['label' => 'Gemma 3 (12B)',           'input_cost' => 0, 'output_cost' => 0],
            'gemma3:27b'        => ['label' => 'Gemma 3 (27B)',           'input_cost' => 0, 'output_cost' => 0],
            'phi4'              => ['label' => 'Phi-4 (14B)',             'input_cost' => 0, 'output_cost' => 0],
            'phi4-mini'         => ['label' => 'Phi-4 Mini (3.8B)',       'input_cost' => 0, 'output_cost' => 0],
            'deepseek-r1'       => ['label' => 'DeepSeek R1 (7B)',        'input_cost' => 0, 'output_cost' => 0],
            'deepseek-r1:14b'   => ['label' => 'DeepSeek R1 (14B)',       'input_cost' => 0, 'output_cost' => 0],
            'codestral'         => ['label' => 'Codestral (22B)',         'input_cost' => 0, 'output_cost' => 0],
        ],
    ],

    'openai_compatible' => [
        'name' => 'OpenAI-Compatible',
        'http_local' => true,
        'default_url' => 'http://localhost:1234/v1',
        'url_hint' => 'URL with /v1 suffix — e.g. http://localhost:1234/v1',
        'models' => [], // Dynamic — set by the user when configuring the endpoint
    ],
];
