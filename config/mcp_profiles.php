<?php

/**
 * MCP tool profiles — predefined sets of compact tools for different use cases.
 *
 * Each profile lists which compact tool names are enabled.
 * null = all tools (resolved dynamically from the server's $tools array).
 *
 * Teams select a profile via settings['mcp_tools']['profile'].
 * Custom mode uses settings['mcp_tools']['enabled'] array instead.
 */
return [
    'essential' => [
        'agent_manage',
        'project_manage',
        'workflow_manage',
        'experiment_manage',
        'crew_manage',
        'budget_manage',
        'memory_manage',
        'system_manage',
        'credential_manage',
        'skill_manage',
        'approval_manage',
    ],

    'standard' => [
        // Core (same as essential)
        'agent_manage',
        'project_manage',
        'workflow_manage',
        'experiment_manage',
        'crew_manage',
        'budget_manage',
        'memory_manage',
        'system_manage',
        'credential_manage',
        'skill_manage',
        'approval_manage',
        // Operations
        'workflow_graph',
        'trigger_manage',
        'tool_manage',
        'signal_manage',
        'signal_connectors',
        'knowledge_manage',
        'artifact_manage',
        'outbound_manage',
        'webhook_manage',
        'team_manage',
        'integration_manage',
    ],

    'full' => null,
];
