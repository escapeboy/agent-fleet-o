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
        // Generic
        'api_polling' => ['label' => 'API Polling',          'auth' => 'api_key',      'poll_frequency' => 300,  'icon' => '🔄', 'category' => 'generic',      'description' => 'Poll any REST API endpoint on a schedule and ingest responses as signals.'],
        'webhook' => ['label' => 'Webhook',              'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🪝', 'category' => 'generic',      'description' => 'Receive HTTP POST events from any service via a unique webhook URL.'],

        // Developer Tools
        'github' => ['label' => 'GitHub',               'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🐙', 'category' => 'developer',    'description' => 'Receive push, PR, and issue events. Manage repos, create issues, and comment on PRs.'],
        'gitlab' => ['label' => 'GitLab',               'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🦊', 'category' => 'developer',    'description' => 'Pipeline, merge request, and push webhooks. Manage projects and issues.'],
        'bitbucket' => ['label' => 'Bitbucket',            'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🪣', 'category' => 'developer',    'description' => 'Repository push and PR events. Manage pull requests and pipelines.'],
        'sentry' => ['label' => 'Sentry',               'auth' => 'oauth2',       'poll_frequency' => 120,  'icon' => '🔍', 'category' => 'developer',    'description' => 'Error tracking and performance monitoring. Get alerts on new issues and regressions.'],
        'datadog' => ['label' => 'Datadog',              'auth' => 'api_key',      'poll_frequency' => 60,   'icon' => '🐶', 'category' => 'developer',    'description' => 'Infrastructure monitoring, APM, and log management. Query metrics and set up alerts.'],
        'posthog' => ['label' => 'PostHog',              'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🦔', 'category' => 'developer',    'description' => 'Product analytics, feature flags, and session recordings. Track events and user funnels.'],
        'vercel' => ['label' => 'Vercel',               'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '▲',  'category' => 'developer',    'description' => 'Deployment platform for frontend apps. Trigger deploys and monitor build status.'],
        'netlify' => ['label' => 'Netlify',              'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🌐', 'category' => 'developer',    'description' => 'Web hosting with CI/CD. Deploy sites, manage DNS, and monitor builds.'],
        'supabase' => ['label' => 'Supabase',             'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '⚡', 'category' => 'developer',    'description' => 'Open-source Firebase alternative. Query databases, manage auth, and access storage.'],
        'ssh_deploy' => ['label' => 'SSH Deploy',           'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🚀', 'category' => 'developer',    'description' => 'Execute remote commands and deploy code via SSH to any server.'],

        // Communication
        'slack' => ['label' => 'Slack',                'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '💬', 'category' => 'communication', 'description' => 'Send and receive messages, react to events, and manage channels in your Slack workspace.'],
        'discord' => ['label' => 'Discord',              'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🎮', 'category' => 'communication', 'description' => 'Bot integration for Discord servers. Send messages, manage channels, and receive events.'],
        'teams' => ['label' => 'Microsoft Teams',      'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🟦', 'category' => 'communication', 'description' => 'Post messages and cards to Teams channels via incoming webhooks.'],
        'whatsapp' => ['label' => 'WhatsApp Business',    'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '💬', 'category' => 'communication', 'description' => 'Send template messages, receive replies, and manage contacts via WhatsApp Business API.'],
        'telegram' => ['label' => 'Telegram',             'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '✈️', 'category' => 'communication', 'description' => 'Telegram bot integration. Send messages, inline keyboards, and receive commands.'],
        'twilio' => ['label' => 'Twilio',               'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📱', 'category' => 'communication', 'description' => 'Programmable SMS, voice calls, and WhatsApp messages via Twilio API.'],

        // Project Management
        'linear' => ['label' => 'Linear',               'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '📋', 'category' => 'project',      'description' => 'Issue tracking for software teams. Create issues, update statuses, and sync projects.'],
        'jira' => ['label' => 'Jira',                 'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🔷', 'category' => 'project',      'description' => 'Atlassian issue tracker. Create and update issues, manage sprints, and track progress.'],
        'asana' => ['label' => 'Asana',                'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🔴', 'category' => 'project',      'description' => 'Task and project management. Create tasks, manage timelines, and track workloads.'],
        'clickup' => ['label' => 'ClickUp',              'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '✅', 'category' => 'project',      'description' => 'All-in-one productivity platform. Manage tasks, docs, goals, and time tracking.'],
        'monday' => ['label' => 'Monday.com',           'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '📆', 'category' => 'project',      'description' => 'Work OS for teams. Manage boards, items, and automations via GraphQL API.'],
        'notion' => ['label' => 'Notion',               'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '📝', 'category' => 'project',      'description' => 'All-in-one workspace. Read and update pages, databases, and blocks.'],
        'confluence' => ['label' => 'Confluence',           'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '📖', 'category' => 'project',      'description' => 'Atlassian team wiki. Create and update pages, search content, and manage spaces.'],

        // CRM & Sales
        'hubspot' => ['label' => 'HubSpot',              'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🧡', 'category' => 'crm',          'description' => 'CRM, marketing, and sales platform. Manage contacts, deals, and email campaigns.'],
        'salesforce' => ['label' => 'Salesforce',           'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '☁️', 'category' => 'crm',          'description' => 'Enterprise CRM. Query and update leads, opportunities, accounts, and custom objects.'],
        'pipedrive' => ['label' => 'Pipedrive',            'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '🔄', 'category' => 'crm',          'description' => 'Sales pipeline CRM. Manage deals, contacts, and activities. Track sales progress.'],
        'attio' => ['label' => 'Attio',                'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🔗', 'category' => 'crm',          'description' => 'Next-gen CRM with flexible data models. Manage records, lists, and automations.'],

        // Customer Support
        'zendesk' => ['label' => 'Zendesk',              'auth' => 'oauth2',       'poll_frequency' => 120,  'icon' => '🎫', 'category' => 'support',      'description' => 'Help desk and ticketing. Create and update tickets, manage agents, and track SLAs.', 'subdomain_required' => true],
        'intercom' => ['label' => 'Intercom',             'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '💬', 'category' => 'support',      'description' => 'Customer messaging platform. Manage conversations, users, and send targeted messages.'],
        'freshdesk' => ['label' => 'Freshdesk',            'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '🎧', 'category' => 'support',      'description' => 'Cloud help desk. Manage tickets, contacts, and automations. Track resolution times.', 'subdomain_required' => true],

        // Marketing & Email
        'mailchimp' => ['label' => 'Mailchimp',            'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🐒', 'category' => 'marketing',    'description' => 'Email marketing platform. Manage lists, send campaigns, and track engagement.'],
        'klaviyo' => ['label' => 'Klaviyo',              'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '📧', 'category' => 'marketing',    'description' => 'Email and SMS marketing for e-commerce. Manage segments, flows, and campaigns.'],
        'segment' => ['label' => 'Segment',              'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '◼',  'category' => 'marketing',    'description' => 'Customer data platform. Collect, clean, and route analytics data to any tool.'],

        // Payments & E-commerce
        'stripe' => ['label' => 'Stripe',               'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '💳', 'category' => 'payments',     'description' => 'Payment processing. Receive payment events, manage customers, and create payment links.'],
        'shopify' => ['label' => 'Shopify',              'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🛍️', 'category' => 'payments',     'description' => 'E-commerce platform. Manage products, orders, customers, and inventory.'],

        // Productivity & Data
        'google' => ['label' => 'Google Workspace',     'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '🔵', 'category' => 'productivity', 'description' => 'Gmail, Calendar, Drive, and Sheets. Read emails, manage events, and access files.'],
        'airtable' => ['label' => 'Airtable',             'auth' => 'oauth2',       'poll_frequency' => 300,  'icon' => '📊', 'category' => 'productivity', 'description' => 'Spreadsheet-database hybrid. Query, create, and update records across bases and tables.'],
        'typeform' => ['label' => 'Typeform',             'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '📋', 'category' => 'productivity', 'description' => 'Form builder with webhooks. Receive form submissions and manage responses.'],
        'calendly' => ['label' => 'Calendly',             'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '📅', 'category' => 'productivity', 'description' => 'Scheduling platform. Receive booking events, manage availability, and sync calendars.'],

        // Social Media
        'linkedin' => ['label' => 'LinkedIn',             'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '💼', 'category' => 'social',       'description' => 'Professional network. Post updates, manage company pages, and access profile data.'],
        'twitter' => ['label' => 'X (Twitter)',          'auth' => 'api_key',      'poll_frequency' => 300,  'icon' => '𝕏',  'category' => 'social',       'description' => 'Social platform. Post tweets, search mentions, and monitor hashtags and trends.'],

        // Desktop & Activity Capture
        'screenpipe' => ['label' => 'Screenpipe',           'auth' => 'none',         'poll_frequency' => 900,  'icon' => '🖥️', 'category' => 'developer',    'description' => 'Local screen & audio capture. Full-text search over everything on your screen, audio transcriptions, and app activity. Runs via screenpipe desktop app.', 'credential_fields' => ['base_url' => ['label' => 'Screenpipe API URL', 'hint' => 'Default: http://localhost:3030']]],

        // Automation & Scraping
        'zapier' => ['label' => 'Zapier',               'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '⚡', 'category' => 'automation',   'description' => 'Connect 6,000+ apps with no-code automations. Trigger Zaps from FleetQ events.'],
        'make' => ['label' => 'Make',                 'auth' => 'webhook_only', 'poll_frequency' => 0,    'icon' => '🔮', 'category' => 'automation',   'description' => 'Visual automation platform. Build complex workflows with branching and iteration.'],
        'activepieces' => ['label' => 'Activepieces',        'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🧩', 'category' => 'automation',   'description' => 'Open-source automation. Self-hostable alternative to Zapier with 200+ connectors.'],
        'apify' => ['label' => 'Apify',                'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🕷️', 'category' => 'automation',   'description' => 'Web scraping and data extraction. 10,000+ pre-built actors for any website or API.'],

        // Incident & Alerting
        'pagerduty' => ['label' => 'PagerDuty',            'auth' => 'oauth2',       'poll_frequency' => 0,    'icon' => '🚨', 'category' => 'alerting',     'description' => 'Incident management. Create and acknowledge incidents, manage on-call schedules.'],

        // Voice & Real-time
        'livekit' => ['label' => 'LiveKit',              'auth' => 'api_key',      'poll_frequency' => 0,    'icon' => '🎙️', 'category' => 'realtime',     'description' => 'Real-time audio/video infrastructure. Create rooms, manage participants, and stream.'],

        // New integrations
        'resend' => ['label' => 'Resend',            'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '✉️', 'category' => 'communication', 'description' => 'Modern email API for developers. Send transactional and marketing emails with high deliverability.', 'credential_fields' => ['api_key' => ['label' => 'API Key', 'hint' => 'resend.com/api-keys']]],
        'sendgrid' => ['label' => 'SendGrid',          'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '📤', 'category' => 'communication', 'description' => 'Cloud email delivery. Send emails, manage templates, and track delivery metrics.', 'credential_fields' => ['api_key' => ['label' => 'API Key', 'hint' => 'app.sendgrid.com/settings/api_keys']]],
        'openai' => ['label' => 'OpenAI',            'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '🤖', 'category' => 'ai',           'description' => 'GPT-4o, DALL-E, Whisper API access. Generate text, images, embeddings, and transcriptions.', 'credential_fields' => ['api_key' => ['label' => 'API Key', 'hint' => 'platform.openai.com/api-keys']]],
        'anthropic' => ['label' => 'Anthropic',         'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '🧠', 'category' => 'ai',           'description' => 'Claude API access. Generate text, analyze images, and use tool-calling capabilities.', 'credential_fields' => ['api_key' => ['label' => 'API Key', 'hint' => 'console.anthropic.com/settings/keys']]],
        'replicate' => ['label' => 'Replicate',         'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '🔁', 'category' => 'ai',           'description' => 'Run open-source ML models in the cloud. Stable Diffusion, LLaMA, Whisper, and more.', 'credential_fields' => ['api_token' => ['label' => 'API Token', 'hint' => 'replicate.com/account/api-tokens']]],
        'pinecone' => ['label' => 'Pinecone',          'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '🌲', 'category' => 'ai',           'description' => 'Vector database for AI. Store, query, and manage embeddings at scale.', 'credential_fields' => ['api_key' => ['label' => 'API Key', 'hint' => 'app.pinecone.io → API Keys']]],
        'firebase' => ['label' => 'Firebase',          'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '🔥', 'category' => 'developer',    'description' => 'Google app platform. Firestore, Auth, Cloud Functions, and push notifications.', 'credential_fields' => ['api_key' => ['label' => 'Web API Key', 'hint' => 'console.firebase.google.com → Project Settings → General']]],
        'aws' => ['label' => 'AWS',               'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '☁️', 'category' => 'developer',    'description' => 'Amazon Web Services. S3, Lambda, SQS, SNS, and 200+ cloud services.', 'credential_fields' => ['access_key_id' => ['label' => 'Access Key ID'], 'secret_access_key' => ['label' => 'Secret Access Key'], 'region' => ['label' => 'Region', 'required' => false, 'hint' => 'e.g. us-east-1']]],
        'cloudflare' => ['label' => 'Cloudflare',        'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '🛡️', 'category' => 'developer',    'description' => 'CDN, DNS, and security. Manage zones, purge cache, and configure firewall rules.', 'credential_fields' => ['api_token' => ['label' => 'API Token', 'hint' => 'dash.cloudflare.com/profile/api-tokens']]],
        'n8n' => ['label' => 'n8n',               'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '🔗', 'category' => 'automation',   'description' => 'Open-source workflow automation. Self-hostable with 400+ integrations and AI nodes.', 'credential_fields' => ['api_key' => ['label' => 'API Key'], 'base_url' => ['label' => 'n8n Instance URL', 'hint' => 'e.g. https://your-n8n.example.com']]],
        'github_actions' => ['label' => 'GitHub Actions', 'auth' => 'api_key', 'poll_frequency' => 0, 'icon' => '⚙️', 'category' => 'developer',    'description' => 'CI/CD workflows. Trigger runs, check statuses, and manage workflow dispatches.', 'credential_fields' => ['token' => ['label' => 'Personal Access Token', 'hint' => 'github.com/settings/tokens — needs "actions" scope']]],
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
