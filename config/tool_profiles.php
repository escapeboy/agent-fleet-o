<?php

return [
    'profiles' => [
        'researcher' => [
            'label' => 'Researcher',
            'description' => 'Web search, memory, knowledge graph, signal analysis',
            'tool_groups' => ['memory', 'knowledge_graph', 'signal', 'metric', 'compact'],
            'max_tools' => 50,
        ],
        'executor' => [
            'label' => 'Executor',
            'description' => 'Full experiment pipeline, workflow, agent, skill execution',
            'tool_groups' => ['experiment', 'workflow', 'agent', 'skill', 'tool', 'credential', 'project'],
            'max_tools' => 80,
        ],
        'communicator' => [
            'label' => 'Communicator',
            'description' => 'Outbound delivery, email, chatbot, telegram, approvals',
            'tool_groups' => ['outbound', 'email', 'chatbot', 'telegram', 'approval', 'shared'],
            'max_tools' => 60,
        ],
        'analyst' => [
            'label' => 'Analyst',
            'description' => 'Metrics, audit, budget, evolution, dashboard',
            'tool_groups' => ['metric', 'audit', 'budget', 'evolution', 'system'],
            'max_tools' => 40,
        ],
        'admin' => [
            'label' => 'Admin',
            'description' => 'Full access to all tool groups',
            'tool_groups' => ['*'],
            'max_tools' => null,
        ],
        'minimal' => [
            'label' => 'Minimal',
            'description' => 'Only compact tools for constrained models',
            'tool_groups' => ['compact'],
            'max_tools' => 34,
        ],
    ],
];
