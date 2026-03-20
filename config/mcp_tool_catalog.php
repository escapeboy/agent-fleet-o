<?php

/**
 * MCP compact tool catalog — used by Team Settings UI and MCP catalog tool.
 *
 * Groups tools by domain with human-readable descriptions.
 * Tool names must match the $name property of the corresponding CompactTool class.
 */
return [
    'groups' => [
        'core' => [
            'label' => 'Core Operations',
            'description' => 'Essential tools for agents, projects, workflows, and experiments',
            'tools' => [
                'agent_manage' => 'Create, list, update, and manage AI agents',
                'project_manage' => 'Manage continuous and one-shot projects with scheduling',
                'workflow_manage' => 'Create and manage workflow templates (DAG builder)',
                'workflow_graph' => 'Edit workflow graph nodes and edges',
                'experiment_manage' => 'Run experiments through the pipeline state machine',
                'crew_manage' => 'Manage multi-agent crews and executions',
                'budget_manage' => 'Monitor budgets, check limits, forecast spend',
                'memory_manage' => 'Semantic memory search, add, delete, and stats',
                'system_manage' => 'Dashboard KPIs, health, audit log, cache management',
                'credential_manage' => 'Manage API keys, OAuth tokens, and secrets',
                'trigger_manage' => 'Event-driven trigger rules for automation',
            ],
        ],
        'operations' => [
            'label' => 'Operations',
            'description' => 'Signal processing, approvals, knowledge, and outbound delivery',
            'tools' => [
                'skill_manage' => 'Manage reusable AI skills and versions',
                'tool_manage' => 'Manage LLM tools (MCP servers, built-in tools)',
                'approval_manage' => 'Approval inbox, human tasks, and webhook config',
                'signal_manage' => 'Inbound signals, contacts, IMAP, email replies',
                'signal_connectors' => 'Connectors: Slack, Telegram, HTTP monitor, tickets, alerts',
                'knowledge_manage' => 'Knowledge base ingestion and search',
                'artifact_manage' => 'List, view, and download experiment artifacts',
                'outbound_manage' => 'Outbound delivery connectors (email, Slack, webhook)',
                'webhook_manage' => 'Inbound webhook endpoint management',
                'team_manage' => 'Team settings, members, BYOK credentials, API tokens',
                'integration_manage' => 'Third-party integration connections',
                'marketplace_manage' => 'Browse, publish, and install marketplace items',
            ],
        ],
        'specialized' => [
            'label' => 'Specialized',
            'description' => 'Email templates, chatbots, Git, AI assistant, and admin tools',
            'tools' => [
                'email_manage' => 'Email templates and themes with AI generation',
                'chatbot_manage' => 'Chatbot instances, tokens, sessions, and analytics',
                'bridge_manage' => 'Bridge agent relay status and endpoints',
                'assistant_manage' => 'AI assistant conversations and messages',
                'git_manage' => 'Git repositories, branches, commits, and PRs',
                'profile_manage' => 'User profile, password, 2FA, social accounts',
                'agent_advanced' => 'Agent config history, rollback, skill/tool sync, feedback',
                'evolution_manage' => 'Evolution proposals: analyze, approve, apply',
                'boruna_manage' => 'Boruna QA: run tests, validate, evidence collection',
                'admin_manage' => 'Super admin: team suspend, billing, security (admin only)',
            ],
        ],
    ],
];
