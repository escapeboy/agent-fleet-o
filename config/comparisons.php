<?php

/*
|--------------------------------------------------------------------------
| Comparison pages (/compare/{slug})
|--------------------------------------------------------------------------
|
| FleetQ cells are taken from the README and the code base. Competitor cells
| only state facts checked on the tool's own public site or docs on the
| 'checked_on' date; anything not checked says "See their docs". No
| competitor prices are stated.
|
*/

$fleetq = [
    'Focus' => 'Control plane for running AI agents in production: approvals, budgets, audit trail and MCP access around your agents.',
    'Self-hosting' => 'Yes. AGPL-3.0 licensed, self-hosted for free with Docker Compose.',
    'Human approvals' => 'Approval inbox with SLA enforcement and escalation, plus human tasks inside workflows.',
    'Budget limits' => 'Credit ledger with pessimistic locks, auto-pause on overspend and project budget caps.',
    'Audit trail' => 'Activity log of every action, searchable and filterable.',
    'MCP server' => 'Built-in MCP server (AgentFleetServer) over HTTP/SSE and stdio.',
    'Bring-your-own LLM keys' => 'BYOK for Anthropic, OpenAI and Google, plus local LLMs such as Ollama, LM Studio and vLLM.',
    'Pricing model' => 'Community Edition is free to self-host. FleetQ Cloud is free to start, with paid tiers.',
];

$table = static fn (): array => array_map(
    static fn (string $label, string $cell): array => [
        'label' => $label,
        'fleetq' => $cell,
        'tool' => 'See their docs',
    ],
    array_keys($fleetq),
    array_values($fleetq),
);

return [

    'checked_on' => '2026-11-08',

    'langgraph' => [
        'tool_name' => 'LangGraph',
        'meta_title' => 'FleetQ vs LangGraph: a LangGraph alternative compared',
        'meta_description' => 'FleetQ vs LangGraph: how a self-hosted agent control plane with approvals, budgets and an MCP server compares to a code-first agent library.',
        'keywords' => 'langgraph alternative, langgraph vs fleetq, fleetq vs langgraph, agent orchestration, self-hosted ai agents',
        'h1' => 'FleetQ vs LangGraph: a control plane next to a code library',
        'intro' => [
            'LangGraph is a library from the LangChain team for building agents as graphs in code. FleetQ is a self-hosted platform for running agents in production: it gives every agent run an approval inbox, a budget, an audit trail and an MCP server, and you manage it from a web UI, a REST API or any MCP client.',
            'The two are not direct substitutes. This page explains where each one fits, so you can decide whether you need one, the other, or both.',
        ],
        'short_answer' => [
            'pick_tool' => 'Pick LangGraph if you want to write your agent logic as Python or JavaScript code, and you value a large ecosystem, a big community and a library that many teams have already used for a long time.',
            'pick_fleetq' => 'Pick FleetQ if you need the operational layer around agents: human approvals with SLAs, hard budget limits, a full activity log, bring-your-own keys and an MCP server, all self-hosted under AGPL-3.0.',
        ],
        'table' => $table(),
        'together' => null,
        'sections' => [
            [
                'heading' => 'Different layers of the stack',
                'paragraphs' => [
                    'A code library is where you describe what an agent does step by step. A control plane is where you decide who may run it, what it may spend, who must approve its actions and how you can audit what happened later. FleetQ focuses on the second job. It still ships its own agents, crews and a visual workflow builder, so you can build without writing graph code, but its main value is the governance around each run.',
                    'If your team already has agents written in code, the question is usually not which tool is better, but which layer is missing. Most teams that move agents from a prototype to production find that approvals, cost limits and audit logs take more time to build than the agent itself.',
                ],
            ],
            [
                'heading' => 'What FleetQ adds when agents go to production',
                'paragraphs' => [
                    'Every risky action can stop in an approval inbox until a person accepts or rejects it, with SLA enforcement and escalation when nobody answers in time. Spend is reserved and settled against a credit ledger with pessimistic locks, so a runaway loop pauses itself instead of draining your provider account. Every change is written to an activity log that you can search and filter.',
                    'You bring your own keys for Anthropic, OpenAI and Google, or point agents at local models through Ollama, LM Studio or vLLM. Nothing leaves your servers unless you send it.',
                ],
            ],
            [
                'heading' => 'How to choose',
                'paragraphs' => [
                    'Start from the problem you have today. If you are still exploring how an agent should reason, a code library gives you fine control. If you already know what your agents should do and now need people, money and compliance to trust them, a control plane saves you from building that layer yourself. Check the LangGraph docs for its current features, since this table only lists what we could confirm.',
                ],
            ],
        ],
        'faqs' => [
            [
                'question' => 'Is FleetQ a LangGraph alternative?',
                'answer' => 'Partly. FleetQ has its own agents, crews and visual workflows, so you can build agents without LangGraph. Its focus is the production layer: approvals, budgets, audit trail and an MCP server.',
            ],
            [
                'question' => 'Is FleetQ open source?',
                'answer' => 'Yes. The Community Edition is licensed under AGPL-3.0 and you can self-host it for free, including for commercial use.',
            ],
            [
                'question' => 'Can other tools call FleetQ?',
                'answer' => 'Yes. FleetQ exposes a REST API and a built-in MCP server over HTTP/SSE and stdio, so any MCP client can create and run agents in FleetQ.',
            ],
        ],
    ],

    'crewai' => [
        'tool_name' => 'CrewAI',
        'meta_title' => 'FleetQ vs CrewAI: a CrewAI alternative compared',
        'meta_description' => 'FleetQ vs CrewAI: compare a self-hosted agent control plane with approvals, budget limits, audit trail and MCP server to a multi-agent framework.',
        'keywords' => 'crewai alternative, crewai vs fleetq, fleetq vs crewai, multi-agent crews, self-hosted ai agents',
        'h1' => 'FleetQ vs CrewAI: running crews in production, not only building them',
        'intro' => [
            'CrewAI is a Python framework for building teams of AI agents that work together. FleetQ also has crews, with roles, goals and coordinated tasks, but it is built as a self-hosted control plane: every crew run gets approvals, a budget, an audit trail and access through an MCP server.',
            'If you are searching for a CrewAI alternative, the useful question is what you need around your crews once real users and real money depend on them.',
        ],
        'short_answer' => [
            'pick_tool' => 'Pick CrewAI if you want to define crews in Python code, and you value a large ecosystem, a big community and a framework that many developers already know well.',
            'pick_fleetq' => 'Pick FleetQ if you want crews you can manage from a web UI, API or MCP client, with human approvals, hard budget limits, a full activity log and bring-your-own keys, self-hosted under AGPL-3.0.',
        ],
        'table' => $table(),
        'together' => null,
        'sections' => [
            [
                'heading' => 'Crews are only half of the job',
                'paragraphs' => [
                    'A framework lets you describe which agent does what. In production you also need to know what a crew may spend, which of its actions need a human to sign off, and what exactly it did last Tuesday. FleetQ treats those as first-class features instead of things you add later.',
                    'In FleetQ a crew is a team of agents with roles and goals that can run tasks in sequence or in parallel. Crews plug into workflows, projects with schedules, and triggers from webhooks, email, RSS and chat tools.',
                ],
            ],
            [
                'heading' => 'What FleetQ adds around each crew',
                'paragraphs' => [
                    'Risky actions can wait in an approval inbox with SLA enforcement and escalation. Spend goes through a credit ledger with pessimistic locks and auto-pause, and projects can carry their own budget caps. Every change lands in a searchable activity log.',
                    'You choose the models. Bring your own keys for Anthropic, OpenAI and Google, or use local LLMs through Ollama, LM Studio or vLLM, so prototypes can run at zero API cost.',
                    'Crews are also reachable from outside the web UI. The REST API and the built-in MCP server let scripts, IDE assistants and other agents create crews, start runs and read results, while the same approval, budget and audit rules apply to every call.',
                ],
            ],
            [
                'heading' => 'How to choose',
                'paragraphs' => [
                    'If your team lives in Python and wants full control in code, a framework is a natural start. If you need operators, reviewers and finance to trust what agents do, a control plane closes that gap without custom glue. Check the CrewAI docs for its current features, since this table only lists what we could confirm.',
                ],
            ],
        ],
        'faqs' => [
            [
                'question' => 'Does FleetQ support multi-agent crews?',
                'answer' => 'Yes. FleetQ crews are teams of agents with roles and goals that run tasks in sequence or in parallel, and they can be used inside visual workflows.',
            ],
            [
                'question' => 'Can I self-host FleetQ?',
                'answer' => 'Yes. The Community Edition is AGPL-3.0 licensed and runs on your own servers with Docker Compose, free of charge.',
            ],
            [
                'question' => 'How does FleetQ stop agents from overspending?',
                'answer' => 'Each run reserves budget from a credit ledger with pessimistic locks. When a limit is reached the work pauses automatically, and projects can have their own budget caps.',
            ],
        ],
    ],

    'n8n' => [
        'tool_name' => 'n8n',
        'meta_title' => 'FleetQ vs n8n: an n8n alternative for AI agents',
        'meta_description' => 'FleetQ vs n8n: compare an AI agent control plane with approvals, budget limits, audit trail and a built-in MCP server to a workflow automation tool.',
        'keywords' => 'n8n alternative, n8n vs fleetq, fleetq vs n8n, ai agent workflows, self-hosted ai automation',
        'h1' => 'FleetQ vs n8n: agent-first control plane or general workflow automation',
        'intro' => [
            'n8n is a workflow automation tool that connects apps and services in visual workflows. FleetQ also has a visual workflow builder, but everything in it is built around AI agents: each run gets approvals, a budget, an audit trail and access through an MCP server.',
            'If you are looking for an n8n alternative for AI agents, this page shows where the two overlap and where they do not.',
        ],
        'short_answer' => [
            'pick_tool' => 'Pick n8n if your main job is connecting many apps with general automation, and you value a large ecosystem of integrations, a big community and a tool that has been used in production for years.',
            'pick_fleetq' => 'Pick FleetQ if agents are the core of your workflows and you need human approvals with SLAs, hard budget limits, a full activity log, bring-your-own keys and an MCP server, self-hosted under AGPL-3.0.',
        ],
        'table' => $table(),
        'together' => null,
        'sections' => [
            [
                'heading' => 'Automation tool or agent control plane',
                'paragraphs' => [
                    'General automation moves data between apps when something happens. Agent work is different: the steps are less predictable, each call costs money, and some actions should not happen without a person saying yes. FleetQ is designed for that kind of work.',
                    'FleetQ workflows are visual graphs with branching, loops and human tasks. Signals from webhooks, email, RSS and chat tools can start them, and results go out by email, Slack, Telegram or webhook.',
                ],
            ],
            [
                'heading' => 'What FleetQ adds for agent workloads',
                'paragraphs' => [
                    'Risky actions wait in an approval inbox with SLA enforcement and escalation. Spend goes through a credit ledger with pessimistic locks and auto-pause, and projects can carry their own budget caps. Every change is recorded in a searchable activity log.',
                    'You bring your own keys for Anthropic, OpenAI and Google, or run local LLMs through Ollama, LM Studio or vLLM.',
                    'Workflows are also reachable from outside the web UI. The REST API and the built-in MCP server let scripts, IDE assistants and other agents start runs and read results, while the same approval, budget and audit rules apply to every call.',
                ],
            ],
            [
                'heading' => 'How to choose',
                'paragraphs' => [
                    'If most of your work is moving records between business apps, a general automation tool is a good fit. If agents make the decisions and you need people and budgets to keep them in check, a control plane built for agents saves custom work. Check the n8n docs for its current features, since this table only lists what we could confirm.',
                ],
            ],
        ],
        'faqs' => [
            [
                'question' => 'Is FleetQ an n8n alternative?',
                'answer' => 'For AI agent workflows, yes. FleetQ has a visual workflow builder with branching, loops and human tasks, built around agents with approvals, budgets and an audit trail.',
            ],
            [
                'question' => 'Can I self-host FleetQ like n8n?',
                'answer' => 'Yes. The FleetQ Community Edition is AGPL-3.0 licensed and runs on your own servers with Docker Compose, free of charge.',
            ],
            [
                'question' => 'Does FleetQ have an MCP server?',
                'answer' => 'Yes. FleetQ ships a built-in MCP server over HTTP/SSE and stdio, so MCP clients can create, run and monitor agents and workflows.',
            ],
        ],
    ],

];
