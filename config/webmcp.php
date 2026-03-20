<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WebMCP Enabled
    |--------------------------------------------------------------------------
    |
    | When enabled, the MCP-B polyfill script is loaded globally and WebMCP
    | tool annotations on forms become discoverable by browser AI agents.
    | This is purely additive — disabling has zero effect on human users.
    |
    */

    'enabled' => env('WEBMCP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Expose Admin Tools
    |--------------------------------------------------------------------------
    |
    | Whether to expose admin-only, billing, and credential management
    | forms as WebMCP tools. Should remain false for security.
    |
    */

    'expose_admin_tools' => false,

    /*
    |--------------------------------------------------------------------------
    | Agent-Side Consumption
    |--------------------------------------------------------------------------
    |
    | When enabled, FleetQ agents with browser skills can discover and
    | call WebMCP tools on target websites via CDP.
    |
    */

    'agent_consumption' => [
        'enabled' => env('WEBMCP_AGENT_CONSUMPTION', false),
        'discovery_timeout_ms' => 3000,
        'prefer_over_screenshot' => true,
    ],

];
