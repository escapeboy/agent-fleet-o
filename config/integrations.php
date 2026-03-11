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