<?php

return [

    'agents' => [
        'codex' => [
            'name' => 'OpenAI Codex',
            'binary' => 'codex',
            'description' => 'AI coding agent by OpenAI',
            'detect_command' => 'codex --version',
            'requires_env' => 'OPENAI_API_KEY',
            'capabilities' => ['code_generation', 'file_editing', 'shell_execution', 'git'],
            'supported_modes' => ['sync', 'streaming'],
        ],
        'claude-code' => [
            'name' => 'Claude Code',
            'binary' => 'claude',
            'description' => 'AI coding agent by Anthropic',
            'detect_command' => 'claude --version',
            'requires_env' => 'ANTHROPIC_API_KEY',
            'capabilities' => ['code_generation', 'file_editing', 'shell_execution', 'git', 'mcp'],
            'supported_modes' => ['sync', 'streaming'],
        ],
    ],

    // Working directory for local agent execution (defaults to project root)
    'working_directory' => env('LOCAL_AGENT_WORKDIR', null),

    // Maximum execution time in seconds
    'timeout' => (int) env('LOCAL_AGENT_TIMEOUT', 300),

    // Enable/disable local agent support
    'enabled' => (bool) env('LOCAL_AGENTS_ENABLED', true),

];
