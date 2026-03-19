<?php

/**
 * CORS Configuration
 *
 * Enables cross-origin requests for the MCP endpoint and OAuth discovery routes
 * so that browser-based MCP clients (Claude.ai, ChatGPT web UI) can connect.
 *
 * The MCP endpoint (/mcp) and OAuth endpoints use Bearer token auth, not session
 * cookies, so relaxed CORS is safe here.
 */

return [

    /*
     * Routes that should have CORS headers applied.
     * The MCP endpoint and all OAuth/discovery routes need to be accessible
     * from browser-based AI clients (claude.ai, chat.openai.com, etc.).
     */
    'paths' => [
        'mcp',
        'oauth/*',
        '.well-known/*',
        'api/*',
    ],

    /*
     * Allowed request origins.
     * '*' allows any origin — appropriate for the MCP/OAuth endpoints which
     * are protected by Bearer tokens, not cookies or CSRF.
     */
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'exposed_headers' => [],

    'max_age' => 86400,

    /*
     * Must be false when allowed_origins is '*'.
     * Credentials (cookies) are not used on the MCP endpoint — only Bearer tokens.
     */
    'supports_credentials' => false,

];
