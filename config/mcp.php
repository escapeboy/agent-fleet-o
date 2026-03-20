<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    'redirect_domains' => [
        // Anthropic / Claude.ai
        'https://claude.ai',
        // OpenAI / ChatGPT Actions
        'https://chatgpt.com',
        'https://chat.openai.com',
        // Local development
        'http://localhost',
        'http://127.0.0.1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins (MCP Spec 2025-11-25 — DNS Rebinding Protection)
    |--------------------------------------------------------------------------
    |
    | Origins allowed to make requests to the /mcp endpoint. The app's own
    | domain is always allowed. If the Origin header is present and its host
    | is not in this list, the server returns 403 Forbidden.
    |
    | Set MCP_ALLOWED_ORIGINS env var to a comma-separated list to override.
    |
    */

    'allowed_origins' => env('MCP_ALLOWED_ORIGINS')
        ? explode(',', env('MCP_ALLOWED_ORIGINS'))
        : [
            'claude.ai',
            'chatgpt.com',
            'chat.openai.com',
            'localhost',
            '127.0.0.1',
        ],

];
