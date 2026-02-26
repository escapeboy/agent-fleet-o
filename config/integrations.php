<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Integration Driver
    |--------------------------------------------------------------------------
    |
    | The default driver resolved when no explicit driver is specified.
    |
    */
    'default' => env('INTEGRATION_DEFAULT', 'webhook'),

    /*
    |--------------------------------------------------------------------------
    | Registered Drivers
    |--------------------------------------------------------------------------
    |
    | Maps driver slugs to their display configuration.
    | auth: 'api_key' | 'oauth2' | 'basic_auth' | 'webhook_only'
    | poll_frequency: seconds (0 = webhook-only, no polling)
    |
    */
    'drivers' => [
        'api_polling' => ['label' => 'API Polling',  'auth' => 'api_key',      'poll_frequency' => 300,  'icon' => '🔄'],
        'webhook'     => ['label' => 'Webhook',      'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🪝'],
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth 2.0 Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Used by OAuthConnectAction and OAuthCallbackAction (Phase 4).
    |
    */
    'oauth' => [
        'slack' => [
            'client_id'     => env('SLACK_CLIENT_ID'),
            'client_secret' => env('SLACK_CLIENT_SECRET'),
            'scopes'        => ['channels:read', 'chat:write', 'reactions:read'],
        ],
        'notion' => [
            'client_id'     => env('NOTION_CLIENT_ID'),
            'client_secret' => env('NOTION_CLIENT_SECRET'),
            'scopes'        => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Settings
    |--------------------------------------------------------------------------
    */
    'health' => [
        'ping_interval_minutes' => 15,
        'error_threshold'       => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Security Settings
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'replay_protection_ttl' => 86400,  // Seconds to cache processed delivery IDs (24h)
        'timestamp_tolerance'   => 300,    // Max age of timestamp header in seconds (5 min)
    ],

];
