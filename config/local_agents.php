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

    // Host bridge — allows Docker containers to reach host-installed agents
    'bridge' => [
        'auto_detect' => (bool) env('LOCAL_AGENT_BRIDGE_AUTO', true),
        'url' => env('LOCAL_AGENT_BRIDGE_URL', 'http://host.docker.internal:8065'),
        'secret' => env('LOCAL_AGENT_BRIDGE_SECRET', ''),
        'connect_timeout' => (int) env('LOCAL_AGENT_BRIDGE_CONNECT_TIMEOUT', 5),
    ],

    // Working directory for local agent execution (defaults to project root)
    'working_directory' => env('LOCAL_AGENT_WORKDIR', null),

    // Maximum execution time in seconds
    'timeout' => (int) env('LOCAL_AGENT_TIMEOUT', 600),

    // Enable/disable local agent support
    'enabled' => (bool) env('LOCAL_AGENTS_ENABLED', true),

];
