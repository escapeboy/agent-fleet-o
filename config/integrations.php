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
        'github' => ['label' => 'GitHub',       'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🐙'],
        'slack' => ['label' => 'Slack',        'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '💬'],
        'stripe' => ['label' => 'Stripe',       'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '💳'],
        'notion' => ['label' => 'Notion',       'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '📝'],
        'airtable' => ['label' => 'Airtable',     'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '📊'],
        'linear' => ['label' => 'Linear',             'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '📋'],
        'discord' => ['label' => 'Discord',            'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🎮'],
        'teams' => ['label' => 'Microsoft Teams',    'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🟦'],
        'whatsapp' => ['label' => 'WhatsApp Business',  'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '💬'],
        'telegram' => ['label' => 'Telegram',           'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '✈️'],
        'datadog' => ['label' => 'Datadog',            'auth' => 'api_key',      'poll_frequency' => 60,   'icon' => '🐶'],
        'sentry' => ['label' => 'Sentry',             'auth' => 'oauth2',       'poll_frequency' => 120,  'icon' => '🔍'],
        'pagerduty' => ['label' => 'PagerDuty',          'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🚨'],
        'hubspot' => ['label' => 'HubSpot',            'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🧡'],
        'salesforce' => ['label' => 'Salesforce',         'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '☁️'],
        'mailchimp' => ['label' => 'Mailchimp',          'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🐒'],
        'klaviyo' => ['label' => 'Klaviyo',            'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📧'],
        'google' => ['label' => 'Google Workspace',  'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '🔵'],
        'jira' => ['label' => 'Jira',              'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🔷'],
        'zapier' => ['label' => 'Zapier',            'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '⚡'],
        'make' => ['label' => 'Make',              'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🔮'],

        // Phase 1 — Simple drivers
        'typeform' => ['label' => 'Typeform',       'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '📋'],
        'calendly' => ['label' => 'Calendly',       'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '📅'],
        'posthog' => ['label' => 'PostHog',         'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🦔'],
        'attio' => ['label' => 'Attio',             'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🔗'],
        'freshdesk' => ['label' => 'Freshdesk',     'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '🎧', 'subdomain_required' => true],
        'segment' => ['label' => 'Segment',         'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '◼'],

        // Phase 2 — Standard drivers
        'gitlab' => ['label' => 'GitLab',           'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🦊'],
        'shopify' => ['label' => 'Shopify',         'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🛍️'],
        'clickup' => ['label' => 'ClickUp',         'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '✅'],
        'pipedrive' => ['label' => 'Pipedrive',     'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '🔄'],
        'confluence' => ['label' => 'Confluence',   'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '📖'],
        'bitbucket' => ['label' => 'Bitbucket',     'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🪣'],

        // Phase 3 — Complex drivers
        'zendesk' => ['label' => 'Zendesk',         'auth' => 'oauth2',       'poll_frequency' => 120,  'icon' => '🎫', 'subdomain_required' => true],
        'intercom' => ['label' => 'Intercom',       'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '💬'],
        'monday' => ['label' => 'Monday.com',       'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '📆'],
        'asana' => ['label' => 'Asana',             'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🔴'],

        // Phase 4 — Advanced drivers
        'twilio' => ['label' => 'Twilio',           'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📱'],

        // Database & backend platforms
        'supabase' => ['label' => 'Supabase',       'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '⚡'],

        // Social media
        'linkedin' => ['label' => 'LinkedIn',       'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '💼'],
        'twitter' => ['label' => 'X (Twitter)',     'auth' => 'api_key',      'poll_frequency' => 300,  'icon' => '𝕏'],
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth 2.0 Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Used by OAuthConnectAction and OAuthCallbackAction.
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
            // Required to receive refresh_token on every authorization (including re-auth)
            'extra_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
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
                'w_member_social',
            ],
        ],

        // GitHub OAuth App — tokens do not expire (unless expiring tokens enabled)
        'github' => [
            'client_id' => env('GITHUB_OAUTH_CLIENT_ID'),
            'client_secret' => env('GITHUB_OAUTH_CLIENT_SECRET'),
            'scopes' => ['repo', 'read:org', 'read:user'],
        ],

        // Airtable requires PKCE (code_challenge_method=S256)
        'airtable' => [
            'client_id' => env('AIRTABLE_CLIENT_ID'),
            'client_secret' => env('AIRTABLE_CLIENT_SECRET'),
            'scopes' => ['data.records:read', 'data.records:write', 'schema.bases:read', 'webhook:manage'],
            'pkce' => true,
        ],

        // Mailchimp — no granular scopes; datacenter fetched from /oauth2/metadata post-callback
        'mailchimp' => [
            'client_id' => env('MAILCHIMP_CLIENT_ID'),
            'client_secret' => env('MAILCHIMP_CLIENT_SECRET'),
            'scopes' => [],
        ],

        // GitLab — supports gitlab.com and self-hosted instances
        'gitlab' => [
            'client_id' => env('GITLAB_CLIENT_ID'),
            'client_secret' => env('GITLAB_CLIENT_SECRET'),
            'scopes' => ['api', 'read_user', 'read_repository'],
        ],

        // ClickUp — token exchange uses query params instead of form body
        'clickup' => [
            'client_id' => env('CLICKUP_CLIENT_ID'),
            'client_secret' => env('CLICKUP_CLIENT_SECRET'),
            'scopes' => [],
        ],

        // Confluence — shares Atlassian OAuth app with Jira (ATLASSIAN_CLIENT_ID / SECRET)
        'confluence' => [
            'client_id' => env('ATLASSIAN_CLIENT_ID'),
            'client_secret' => env('ATLASSIAN_CLIENT_SECRET'),
            'scopes' => ['read:confluence-content.all', 'write:confluence-content', 'offline_access'],
            'extra_params' => ['audience' => 'api.atlassian.com', 'prompt' => 'consent'],
        ],

        // Bitbucket — shares Atlassian OAuth app with Jira/Confluence
        'bitbucket' => [
            'client_id' => env('ATLASSIAN_CLIENT_ID'),
            'client_secret' => env('ATLASSIAN_CLIENT_SECRET'),
            'scopes' => ['repository:read', 'repository:write', 'pullrequest:read', 'pullrequest:write'],
        ],

        // Zendesk — subdomain is collected pre-OAuth via the integration name field
        'zendesk' => [
            'client_id' => env('ZENDESK_CLIENT_ID'),
            'client_secret' => env('ZENDESK_CLIENT_SECRET'),
            'scopes' => ['read', 'write'],
        ],

        'intercom' => [
            'client_id' => env('INTERCOM_CLIENT_ID'),
            'client_secret' => env('INTERCOM_CLIENT_SECRET'),
            'scopes' => [],
        ],

        'monday' => [
            'client_id' => env('MONDAY_CLIENT_ID'),
            'client_secret' => env('MONDAY_CLIENT_SECRET'),
            'scopes' => ['me:read', 'boards:read', 'boards:write', 'updates:write'],
        ],

        'asana' => [
            'client_id' => env('ASANA_CLIENT_ID'),
            'client_secret' => env('ASANA_CLIENT_SECRET'),
            'scopes' => ['default'],
        ],

        'pipedrive' => [
            'client_id' => env('PIPEDRIVE_CLIENT_ID'),
            'client_secret' => env('PIPEDRIVE_CLIENT_SECRET'),
            'scopes' => ['base', 'deals:full', 'contacts:full', 'activities:full'],
        ],

        'sentry' => [
            'client_id' => env('SENTRY_CLIENT_ID'),
            'client_secret' => env('SENTRY_CLIENT_SECRET'),
            'scopes' => ['project:read', 'event:read', 'org:read'],
        ],

        'pagerduty' => [
            'client_id' => env('PAGERDUTY_CLIENT_ID'),
            'client_secret' => env('PAGERDUTY_CLIENT_SECRET'),
            'scopes' => ['read', 'write'],
        ],

        'typeform' => [
            'client_id' => env('TYPEFORM_CLIENT_ID'),
            'client_secret' => env('TYPEFORM_CLIENT_SECRET'),
            'scopes' => ['forms:read', 'responses:read', 'webhooks:write'],
        ],

        'calendly' => [
            'client_id' => env('CALENDLY_CLIENT_ID'),
            'client_secret' => env('CALENDLY_CLIENT_SECRET'),
            'scopes' => [],
        ],

        'attio' => [
            'client_id' => env('ATTIO_CLIENT_ID'),
            'client_secret' => env('ATTIO_CLIENT_SECRET'),
            'scopes' => ['record_permission:read', 'record_permission:read-write', 'object_configuration:read'],
        ],

        'freshdesk' => [
            'client_id' => env('FRESHDESK_CLIENT_ID'),
            'client_secret' => env('FRESHDESK_CLIENT_SECRET'),
            'scopes' => ['read', 'write'],
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
        'github' => [
            'authorize' => 'https://github.com/login/oauth/authorize',
            'token' => 'https://github.com/login/oauth/access_token',
        ],
        'airtable' => [
            'authorize' => 'https://airtable.com/oauth2/v1/authorize',
            'token' => 'https://airtable.com/oauth2/v1/token',
        ],
        'mailchimp' => [
            'authorize' => 'https://login.mailchimp.com/oauth2/authorize',
            'token' => 'https://login.mailchimp.com/oauth2/token',
        ],
        'gitlab' => [
            'authorize' => 'https://gitlab.com/oauth/authorize',
            'token' => 'https://gitlab.com/oauth/token',
        ],
        'clickup' => [
            'authorize' => 'https://app.clickup.com/api',
            'token' => 'https://api.clickup.com/api/v2/oauth/token',
        ],
        'confluence' => [
            'authorize' => 'https://auth.atlassian.com/authorize',
            'token' => 'https://auth.atlassian.com/oauth/token',
        ],
        'bitbucket' => [
            'authorize' => 'https://bitbucket.org/site/oauth2/authorize',
            'token' => 'https://bitbucket.org/site/oauth2/access_token',
        ],
        'zendesk' => [
            // Zendesk URLs are subdomain-specific — resolved dynamically in OAuthConnectAction
            'authorize' => null,
            'token' => null,
        ],
        'intercom' => [
            'authorize' => 'https://app.intercom.com/oauth',
            'token' => 'https://api.intercom.io/auth/eagle/token',
        ],
        'monday' => [
            'authorize' => 'https://auth.monday.com/oauth2/authorize',
            'token' => 'https://auth.monday.com/oauth2/token',
        ],
        'asana' => [
            'authorize' => 'https://app.asana.com/-/oauth_authorize',
            'token' => 'https://app.asana.com/-/oauth_token',
        ],
        'pipedrive' => [
            'authorize' => 'https://oauth.pipedrive.com/oauth/authorize',
            'token' => 'https://oauth.pipedrive.com/oauth/token',
        ],
        'sentry' => [
            'authorize' => 'https://sentry.io/oauth/authorize/',
            'token' => 'https://sentry.io/api/0/sentry-internal-app-tokens/',
        ],
        'pagerduty' => [
            'authorize' => 'https://app.pagerduty.com/oauth/authorize',
            'token' => 'https://app.pagerduty.com/oauth/token',
        ],
        'typeform' => [
            'authorize' => 'https://api.typeform.com/oauth/authorize',
            'token' => 'https://api.typeform.com/oauth/token',
        ],
        'calendly' => [
            'authorize' => 'https://auth.calendly.com/oauth/authorize',
            'token' => 'https://auth.calendly.com/oauth/token',
        ],
        'attio' => [
            'authorize' => 'https://app.attio.com/authorize',
            'token' => 'https://app.attio.com/oauth/token',
        ],
        'freshdesk' => [
            // Freshdesk URLs are subdomain-specific — resolved dynamically in OAuthConnectAction
            'authorize' => null,
            'token' => null,
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
