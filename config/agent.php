<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bash Sandbox Mode
    |--------------------------------------------------------------------------
    | 'php'       — Commands run in-process with CommandSecurityPolicy allowlist (default, dev-friendly)
    | 'docker'    — Commands run inside a Docker container with --network none, --read-only, workspace mount
    | 'just_bash' — Commands run inside a persistent just-bash Node.js sidecar (recommended for cloud)
    */
    'bash_sandbox_mode' => env('AGENT_BASH_SANDBOX_MODE', 'php'),

    /*
    |--------------------------------------------------------------------------
    | just-bash Sidecar URL
    |--------------------------------------------------------------------------
    | Base URL of the bash-sidecar Docker container. Only used when
    | bash_sandbox_mode is 'just_bash'.
    */
    'bash_sidecar_url' => env('BASH_SIDECAR_URL', 'http://bash_sidecar:3001'),

    /*
    |--------------------------------------------------------------------------
    | just-bash Sidecar Secret
    |--------------------------------------------------------------------------
    | Bearer token required by the sidecar HTTP API. Must match the
    | BASH_SIDECAR_SECRET env var set in the sidecar container.
    */
    'bash_sidecar_secret' => env('BASH_SIDECAR_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Browser Sandbox Mode
    |--------------------------------------------------------------------------
    | 'disabled' — Browser tool returns a plan-upgrade prompt (default)
    | 'cloud'    — Tasks delegated to browser-use Cloud REST API (api.browser-use.com)
    | 'sidecar'  — Tasks run in a self-hosted Python Docker sidecar (Phase 2)
    */
    'browser_sandbox_mode' => env('AGENT_BROWSER_SANDBOX_MODE', 'disabled'),

    /*
    |--------------------------------------------------------------------------
    | browser-use Cloud API Key
    |--------------------------------------------------------------------------
    | Used when browser_sandbox_mode is 'cloud'. Can also be stored per-team
    | in Tool.credentials['api_key'] (encrypted via TeamEncryptedArray).
    | Get your key at: https://cloud.browser-use.com/settings
    */
    'browser_use_cloud_api_key' => env('BROWSER_USE_CLOUD_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Browser Sidecar URL & Secret
    |--------------------------------------------------------------------------
    | Base URL and bearer token for the browser-use Python sidecar container.
    | Only used when browser_sandbox_mode is 'sidecar' (Phase 2).
    */
    'browser_sidecar_url' => env('BROWSER_SIDECAR_URL', 'http://browser_sidecar:8090'),
    'browser_sidecar_secret' => env('BROWSER_SIDECAR_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Docker Sandbox Image
    |--------------------------------------------------------------------------
    | The Docker image used when bash_sandbox_mode is 'docker'.
    */
    'sandbox_image' => env('AGENT_SANDBOX_IMAGE', 'python:3.12-alpine'),

    /*
    |--------------------------------------------------------------------------
    | Sandbox Root Directory
    |--------------------------------------------------------------------------
    | Base directory for per-execution sandbox directories.
    | Must be writable by the application process.
    */
    'sandbox_root' => storage_path('app/sandboxes'),

    /*
    |--------------------------------------------------------------------------
    | Sandbox Max Size (MB)
    |--------------------------------------------------------------------------
    | Soft limit for per-execution sandbox disk usage (for future quota enforcement).
    */
    'sandbox_max_size_mb' => (int) env('AGENT_SANDBOX_MAX_SIZE_MB', 100),

    /*
    |--------------------------------------------------------------------------
    | MCP Stdio Binary Allowlist
    |--------------------------------------------------------------------------
    | Comma-separated list of absolute paths to binaries that may be spawned
    | by McpStdioClient. When non-empty, only listed binaries are permitted.
    |
    | When EMPTY (default):
    |   - If MCP_STDIO_ALLOW_ANY_BINARY=true  → allow all (local dev opt-in)
    |   - If MCP_STDIO_ALLOW_ANY_BINARY=false → deny all (fail-close, safe default)
    |
    | Production: always set an explicit list.
    |   MCP_STDIO_BINARY_ALLOWLIST=/usr/local/bin/boruna,/usr/local/bin/my-mcp-server
    */
    'mcp_stdio_binary_allowlist' => array_filter(
        explode(',', env('MCP_STDIO_BINARY_ALLOWLIST', '')),
        fn ($v) => $v !== '',
    ),

    /*
    |--------------------------------------------------------------------------
    | MCP Stdio Allow Any Binary (Dev Opt-In)
    |--------------------------------------------------------------------------
    | When true AND mcp_stdio_binary_allowlist is empty, McpStdioClient will
    | allow any binary path. Only for local development. NEVER enable in production.
    */
    'mcp_stdio_allow_any_binary' => (bool) env('MCP_STDIO_ALLOW_ANY_BINARY', false),

    /*
    |--------------------------------------------------------------------------
    | Boruna Binary Path
    |--------------------------------------------------------------------------
    | Absolute path to the Boruna binary on the host / inside the container.
    | Used by the PopularToolsSeeder and install wizard to auto-configure the
    | default Boruna mcp_stdio Tool.
    */
    'boruna_binary_path' => env('BORUNA_BINARY_PATH', '/usr/local/bin/boruna'),

    /*
    |--------------------------------------------------------------------------
    | Agent-as-Tool Max Nesting Depth
    |--------------------------------------------------------------------------
    | Maximum recursion depth when agents call other agents as tools.
    | Prevents infinite loops in agent-to-agent delegation chains.
    */
    'max_agent_tool_depth' => (int) env('AGENT_MAX_TOOL_DEPTH', 3),

    /*
    |--------------------------------------------------------------------------
    | Tool Loop Circuit Breaker Thresholds
    |--------------------------------------------------------------------------
    | Warning  — log a warning when an agent uses this many LLM steps.
    | Critical — throw ToolLoopCriticalException and fail the execution.
    | Global   — rolling 1-hour per-team step count that pauses all agents.
    |
    | Inspired by BroodMind's BROODMIND_TOOL_LOOP_* env vars.
    */
    'tool_loop' => [
        'warning_threshold' => (int) env('AGENT_TOOL_LOOP_WARNING', 8),
        'critical_threshold' => (int) env('AGENT_TOOL_LOOP_CRITICAL', 12),
        'global_breaker' => (int) env('AGENT_TOOL_LOOP_GLOBAL', 30),
        // Semantic loop detection: fires when the exact same tool call set repeats
        'semantic_warn_threshold' => (int) env('AGENT_TOOL_LOOP_SEMANTIC_WARN', 3),
        'semantic_critical_threshold' => (int) env('AGENT_TOOL_LOOP_SEMANTIC_CRITICAL', 5),
    ],
];
