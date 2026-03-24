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
        'linear' => ['label' => 'Linear',             'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '📋'],
        'discord' => ['label' => 'Discord',            'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🎮'],
        'teams' => ['label' => 'Microsoft Teams',    'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🟦'],
        'whatsapp' => ['label' => 'WhatsApp Business',  'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '💬'],
        'telegram' => ['label' => 'Telegram',           'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '✈️'],
        'datadog' => ['label' => 'Datadog',            'auth' => 'api_key',      'poll_frequency' => 60,   'icon' => '🐶'],
        'sentry' => ['label' => 'Sentry',             'auth' => 'api_key',      'poll_frequency' => 120,  'icon' => '🔍'],
        'pagerduty' => ['label' => 'PagerDuty',          'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🚨'],
        'hubspot' => ['label' => 'HubSpot',            'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🧡'],
        'salesforce' => ['label' => 'Salesforce',         'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '☁️'],
        'mailchimp' => ['label' => 'Mailchimp',          'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🐒'],
        'klaviyo' => ['label' => 'Klaviyo',            'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📧'],
        'google' => ['label' => 'Google Workspace',  'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '🔵'],
        'jira' => ['label' => 'Jira',              'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🔷'],
        'zapier' => ['label' => 'Zapier',            'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '⚡'],
        'make' => ['label' => 'Make',              'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🔮'],

        // Phase 1 — Simple drivers
        'typeform' => ['label' => 'Typeform',       'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📋'],
        'calendly' => ['label' => 'Calendly',       'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📅'],
        'posthog' => ['label' => 'PostHog',         'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🦔'],
        'attio' => ['label' => 'Attio',             'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🔗'],
        'freshdesk' => ['label' => 'Freshdesk',     'auth' => 'api_key',      'poll_frequency' => 300,  'icon' => '🎧'],
        'segment' => ['label' => 'Segment',         'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '◼'],

        // Phase 2 — Standard drivers
        'gitlab' => ['label' => 'GitLab',           'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🦊'],
        'shopify' => ['label' => 'Shopify',         'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🛍️'],
        'clickup' => ['label' => 'ClickUp',         'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '✅'],
        'pipedrive' => ['label' => 'Pipedrive',     'auth' => 'api_key',      'poll_frequency' => 300,  'icon' => '🔄'],
        'confluence' => ['label' => 'Confluence',   'auth' => 'api_key',      'poll_frequency' => 300,  'icon' => '📖'],
        'bitbucket' => ['label' => 'Bitbucket',     'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🪣'],

        // Phase 3 — Complex drivers
        'zendesk' => ['label' => 'Zendesk',         'auth' => 'api_key',      'poll_frequency' => 120,  'icon' => '🎫'],
        'intercom' => ['label' => 'Intercom',       'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '💬'],
        'monday' => ['label' => 'Monday.com',       'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📆'],
        'asana' => ['label' => 'Asana',             'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🔴'],

        // Phase 4 — Advanced drivers
        'twilio' => ['label' => 'Twilio',           'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📱'],

        // Database & backend platforms
        'supabase' => ['label' => 'Supabase',       'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '⚡'],

        // Social media
        'linkedin' => ['label' => 'LinkedIn',       'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '💼'],
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
        'hubspot' => [
            'client_id' => env('HUBSPOT_CLIENT_ID'),
            'client_secret' => env('HUBSPOT_CLIENT_SECRET'),
            'scopes' => ['crm.objects.contacts.read', 'crm.objects.contacts.write', 'crm.objects.deals.read', 'crm.objects.deals.write', 'tickets'],
        ],
        'salesforce' => [
            'client_id' => env('SALESFORCE_CLIENT_ID'),
            'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
            'scopes' => ['api', 'refresh_token', 'offline_access'],
        ],
        'google' => [
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'scopes' => [
                'https://www.googleapis.com/auth/spreadsheets',
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/drive.file',
                'https://www.googleapis.com/auth/userinfo.email',
                'openid',
            ],
        ],
        'linear' => [
            'client_id' => env('LINEAR_CLIENT_ID'),
            'client_secret' => env('LINEAR_CLIENT_SECRET'),
            // read: read user data; write: create/update issues; issues:create for webhook scope
            'scopes' => ['read', 'write', 'issues:create'],
        ],
        'jira' => [
            'client_id' => env('JIRA_CLIENT_ID'),
            'client_secret' => env('JIRA_CLIENT_SECRET'),
            // offline_access → refresh tokens; manage:jira-webhook → register webhooks
            'scopes' => ['read:jira-work', 'write:jira-work', 'offline_access', 'manage:jira-webhook'],
            // Atlassian requires audience and prompt=consent for refresh tokens
            'extra_params' => ['audience' => 'api.atlassian.com', 'prompt' => 'consent'],
        ],

        // LinkedIn: uses the same app credentials as social login (LINKEDIN_CLIENT_ID / SECRET).
        // Phase 1 scopes (self-service, instant approval via "Share on LinkedIn" product):
        //   openid, profile, email, w_member_social
        // Phase 2 scopes (require Community Management API approval, 2-4 weeks):
        //   w_organization_social, w_member_social_feed, w_organization_social_feed
        'linkedin' => [
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
            'scopes' => [
                'openid',
                'profile',
                'email',
                'w_member_social',
                'w_organization_social',
                'w_member_social_feed',
                'w_organization_social_feed',
            ],
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
        'hubspot' => [
            'authorize' => 'https://app.hubspot.com/oauth/authorize',
            'token' => 'https://api.hubapi.com/oauth/v1/token',
        ],
        'salesforce' => [
            'authorize' => 'https://login.salesforce.com/services/oauth2/authorize',
            'token' => 'https://login.salesforce.com/services/oauth2/token',
        ],
        'google' => [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
        ],
        'linear' => [
            'authorize' => 'https://linear.app/oauth/authorize',
            'token' => 'https://api.linear.app/oauth/token',
        ],
        'jira' => [
            'authorize' => 'https://auth.atlassian.com/authorize',
            'token' => 'https://auth.atlassian.com/oauth/token',
        ],

        'linkedin' => [
            'authorize' => 'https://www.linkedin.com/oauth/v2/authorization',
            'token' => 'https://www.linkedin.com/oauth/v2/accessToken',
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
