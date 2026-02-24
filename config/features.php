<?php

return [
    'self-hosted' => [
        'smtp_connector',    // Users configure their own SMTP
        'local_agents',      // Filesystem/bash/browser built-in tools
        'mcp_host_scan',     // Scan host filesystem for MCP configs
        'security_policy',   // Custom blocked/allowed command policies
        'built_in_tools',    // Built-in bash/filesystem/browser tool type
    ],

    'cloud' => [
        'managed_email',     // Platform-managed email (no SMTP config exposed)
        'billing',           // Stripe subscription management
        'usage_limits',      // Per-team usage quota enforcement
    ],

    'shared' => [
        'agents',
        'experiments',
        'skills',
        'approvals',
        'outbound_webhook',
        'outbound_slack',
        'outbound_telegram',
        'outbound_discord',
        'marketplace',
    ],
];
