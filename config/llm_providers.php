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
    'groq' => [
        'name' => 'Groq',
        'models' => [
            'llama-3.3-70b-versatile' => ['label' => 'Llama 3.3 70B', 'input_cost' => 0.59, 'output_cost' => 0.79],
            'llama-3.1-8b-instant' => ['label' => 'Llama 3.1 8B', 'input_cost' => 0.05, 'output_cost' => 0.08],
            'llama-4-scout-17b-16e' => ['label' => 'Llama 4 Scout', 'input_cost' => 0.11, 'output_cost' => 0.34],
            'gemma2-9b-it' => ['label' => 'Gemma 2 9B', 'input_cost' => 0.20, 'output_cost' => 0.20],
            'qwen-qwq-32b' => ['label' => 'Qwen QwQ 32B', 'input_cost' => 0.29, 'output_cost' => 0.39],
            'mixtral-8x7b-32768' => ['label' => 'Mixtral 8x7B', 'input_cost' => 0.24, 'output_cost' => 0.24],
        ],
    ],
    'openrouter' => [
        'name' => 'OpenRouter',
        'models' => [
            'meta-llama/llama-3.3-70b-instruct:free' => ['label' => 'Llama 3.3 70B (free)', 'input_cost' => 0, 'output_cost' => 0],
            'qwen/qwen3-coder-480b-a35b:free' => ['label' => 'Qwen3 Coder 480B (free)', 'input_cost' => 0, 'output_cost' => 0],
            'google/gemma-3-27b-it:free' => ['label' => 'Gemma 3 27B (free)', 'input_cost' => 0, 'output_cost' => 0],
            'mistralai/mistral-small-3.1-24b-instruct:free' => ['label' => 'Mistral Small 3.1 (free)', 'input_cost' => 0, 'output_cost' => 0],
            'openrouter/free' => ['label' => 'Auto (best free model)', 'input_cost' => 0, 'output_cost' => 0],
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
    'gemini-cli' => [
        'name' => 'Gemini CLI (Local)',
        'local' => true,
        'agent_key' => 'gemini-cli',
        'models' => [
            'gemini-2.5-pro' => ['label' => 'Gemini 2.5 Pro', 'input_cost' => 0, 'output_cost' => 0],
            'gemini-2.5-flash' => ['label' => 'Gemini 2.5 Flash', 'input_cost' => 0, 'output_cost' => 0],
            'gemini-3-pro' => ['label' => 'Gemini 3 Pro', 'input_cost' => 0, 'output_cost' => 0],
        ],
    ],
    'kiro' => [
        'name' => 'Kiro CLI (Local)',
        'local' => true,
        'agent_key' => 'kiro',
        'models' => [
            'kiro-default' => ['label' => 'Kiro (Default)', 'input_cost' => 0, 'output_cost' => 0],
        ],
    ],
    'aider' => [
        'name' => 'Aider (Local)',
        'local' => true,
        'agent_key' => 'aider',
        'models' => [
            'aider-default' => ['label' => 'Aider (Default)', 'input_cost' => 0, 'output_cost' => 0],
        ],
    ],
    'amp' => [
        'name' => 'Amp (Local)',
        'local' => true,
        'agent_key' => 'amp',
        'models' => [
            'amp-default' => ['label' => 'Amp (Default)', 'input_cost' => 0, 'output_cost' => 0],
        ],
    ],
    'opencode' => [
        'name' => 'OpenCode (Local)',
        'local' => true,
        'agent_key' => 'opencode',
        'models' => [
            'opencode-default' => ['label' => 'OpenCode (Default)', 'input_cost' => 0, 'output_cost' => 0],
        ],
    ],
    'cursor' => [
        'name' => 'Cursor (Local)',
        'local' => true,
        'agent_key' => 'cursor',
        'models' => [
            'auto'           => ['label' => 'Auto (best available)',        'input_cost' => 0, 'output_cost' => 0],
            'sonnet-4'       => ['label' => 'Claude Sonnet 4',              'input_cost' => 0, 'output_cost' => 0],
            'gpt-5'          => ['label' => 'GPT-5',                        'input_cost' => 0, 'output_cost' => 0],
            'gemini-2.5-pro' => ['label' => 'Gemini 2.5 Pro',              'input_cost' => 0, 'output_cost' => 0],
            'composer-1.5'   => ['label' => 'Composer 1.5 (Cursor native)', 'input_cost' => 0, 'output_cost' => 0],
        ],
    ],

    // HTTP-based local LLM providers (Ollama, OpenAI-compatible endpoints)
    'ollama' => [
        'name' => 'Ollama',
        'http_local' => true,
        'default_url' => 'http://localhost:11434',
        'url_hint' => 'Root URL without /v1 suffix — e.g. http://localhost:11434',
        'models' => [
            'llama3.3' => ['label' => 'Llama 3.3 (70B)',         'input_cost' => 0, 'output_cost' => 0],
            'llama3.2' => ['label' => 'Llama 3.2 (3B)',          'input_cost' => 0, 'output_cost' => 0],
            'llama3.1' => ['label' => 'Llama 3.1 (8B)',          'input_cost' => 0, 'output_cost' => 0],
            'mistral' => ['label' => 'Mistral 7B',              'input_cost' => 0, 'output_cost' => 0],
            'mistral-nemo' => ['label' => 'Mistral Nemo (12B)',      'input_cost' => 0, 'output_cost' => 0],
            'qwen2.5' => ['label' => 'Qwen 2.5 (7B)',           'input_cost' => 0, 'output_cost' => 0],
            'qwen2.5:14b' => ['label' => 'Qwen 2.5 (14B)',          'input_cost' => 0, 'output_cost' => 0],
            'qwen2.5:32b' => ['label' => 'Qwen 2.5 (32B)',          'input_cost' => 0, 'output_cost' => 0],
            'qwen2.5-coder' => ['label' => 'Qwen 2.5 Coder (7B)',     'input_cost' => 0, 'output_cost' => 0],
            'gemma3' => ['label' => 'Gemma 3 (4B)',            'input_cost' => 0, 'output_cost' => 0],
            'gemma3:12b' => ['label' => 'Gemma 3 (12B)',           'input_cost' => 0, 'output_cost' => 0],
            'gemma3:27b' => ['label' => 'Gemma 3 (27B)',           'input_cost' => 0, 'output_cost' => 0],
            'phi4' => ['label' => 'Phi-4 (14B)',             'input_cost' => 0, 'output_cost' => 0],
            'phi4-mini' => ['label' => 'Phi-4 Mini (3.8B)',       'input_cost' => 0, 'output_cost' => 0],
            'deepseek-r1' => ['label' => 'DeepSeek R1 (7B)',        'input_cost' => 0, 'output_cost' => 0],
            'deepseek-r1:14b' => ['label' => 'DeepSeek R1 (14B)',       'input_cost' => 0, 'output_cost' => 0],
            'codestral' => ['label' => 'Codestral (22B)',         'input_cost' => 0, 'output_cost' => 0],
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
