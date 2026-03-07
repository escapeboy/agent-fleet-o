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
        'webhook' => ['label' => 'Webhook',      'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🪝'],
        'github' => ['label' => 'GitHub',       'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🐙'],
        'slack' => ['label' => 'Slack',        'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '💬'],
        'stripe' => ['label' => 'Stripe',       'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '💳'],
        'notion' => ['label' => 'Notion',       'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '📝'],
        'airtable' => ['label' => 'Airtable',     'auth' => 'api_key',      'poll_frequency' => 300,  'icon' => '📊'],
        'linear'    => ['label' => 'Linear',             'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📋'],
        'discord'   => ['label' => 'Discord',            'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🎮'],
        'teams'     => ['label' => 'Microsoft Teams',    'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🟦'],
        'whatsapp'  => ['label' => 'WhatsApp Business',  'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '💬'],
        'telegram'  => ['label' => 'Telegram',           'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '✈️'],
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
            'client_id' => env('SLACK_CLIENT_ID'),
            'client_secret' => env('SLACK_CLIENT_SECRET'),
            'scopes' => ['channels:read', 'chat:write', 'reactions:read'],
        ],
        'notion' => [
            'client_id' => env('NOTION_CLIENT_ID'),
            'client_secret' => env('NOTION_CLIENT_SECRET'),
            'scopes' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth 2.0 Provider URLs
    |--------------------------------------------------------------------------
    |
    | Authorization and token endpoint URLs for each OAuth2 driver.
    |
    */
    'oauth_urls' => [
        'slack' => [
            'authorize' => 'https://slack.com/oauth/v2/authorize',
            'token' => 'https://slack.com/api/oauth.v2.access',
        ],
        'notion' => [
            'authorize' => 'https://api.notion.com/v1/oauth/authorize',
            'token' => 'https://api.notion.com/v1/oauth/token',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Settings
    |--------------------------------------------------------------------------
    */
    'health' => [
        'ping_interval_minutes' => 15,
        'error_threshold' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Security Settings
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'replay_protection_ttl' => 86400,  // Seconds to cache processed delivery IDs (24h)
        'timestamp_tolerance' => 300,    // Max age of timestamp header in seconds (5 min)
    ],

];
