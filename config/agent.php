<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bash Sandbox Mode
    |--------------------------------------------------------------------------
    | 'php'    — Commands run in-process with CommandSecurityPolicy allowlist (default, dev-friendly)
    | 'docker' — Commands run inside a Docker container with --network none, --read-only, workspace mount
    */
    'bash_sandbox_mode' => env('AGENT_BASH_SANDBOX_MODE', 'php'),

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
];
