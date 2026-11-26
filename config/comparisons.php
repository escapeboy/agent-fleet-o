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

$multiTable = static fn (array $tools): array => array_map(
    static fn (array $row): array => $row + [
        'tools' => array_fill_keys($tools, 'See their docs'),
    ],
    $table(),
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

    'dify' => [
        'tool_name' => 'Dify',
        'meta_title' => 'FleetQ vs Dify: a self-hosted Dify alternative',
        'meta_description' => 'FleetQ vs Dify: compare an AI agent control plane with approvals, budget limits, audit trail and an MCP server to a visual AI app builder.',
        'keywords' => 'dify alternative, dify vs fleetq, fleetq vs dify, self-hosted dify, ai agent governance',
        'h1' => 'FleetQ vs Dify: governing agents in production or building AI apps',
        'intro' => [
            'Dify is a platform for building AI apps in a visual, no-code builder, with knowledge bases that let an app answer from your own documents. FleetQ is a self-hosted control plane for running AI agents in production: every run gets an approval inbox, a budget, an audit trail and access through an MCP server.',
            'If you are looking for a Dify alternative, it helps to know which job you want done first. This page explains where each tool fits, so you can decide whether you need one, the other, or both.',
        ],
        'short_answer' => [
            'pick_tool' => 'Pick Dify if you want a visual, no-code builder for AI apps with built-in document retrieval through knowledge bases, so you can ship a chatbot or assistant over your own content quickly.',
            'pick_fleetq' => 'Pick FleetQ if you need the operational layer around agents: an approval inbox with SLAs, hard budget limits with auto-pause, a full activity log, bring-your-own keys and an MCP server, all self-hosted under AGPL-3.0.',
        ],
        'table' => $table(),
        'together' => null,
        'sections' => [
            [
                'heading' => 'Where they differ',
                'paragraphs' => [
                    'An app builder is where you design what an AI app looks like to its users: the prompt, the documents it can draw on and the screen it lives in. A control plane is where you decide who may run an agent, what it may spend, which of its actions need a person to sign off and how you can audit what happened later. FleetQ focuses on the second job.',
                    'FleetQ still ships its own agents, crews and a visual workflow builder with branching, loops and human tasks, so you can build without writing code. Signals from webhooks, email, RSS and chat tools can start a run, and results go out by email, Slack, Telegram or webhook. Its main value, though, is the governance around each run.',
                ],
            ],
            [
                'heading' => 'What FleetQ adds for agents in production',
                'paragraphs' => [
                    'Risky actions wait in an approval inbox with SLA enforcement and escalation when nobody answers in time. Spend is reserved and settled against a credit ledger with pessimistic locks, so a runaway loop pauses itself instead of draining your provider account, and projects can carry their own budget caps. Every change is written to an activity log that you can search and filter.',
                    'You bring your own keys for Anthropic, OpenAI and Google, or point agents at local models through Ollama, LM Studio or vLLM. Nothing leaves your servers unless you send it.',
                    'Agents are also reachable from outside the web UI. The REST API and the built-in MCP server let scripts, IDE assistants and other agents start runs and read results, while the same approval, budget and audit rules apply to every call.',
                ],
            ],
            [
                'heading' => 'How to choose',
                'paragraphs' => [
                    'If your goal is to put an AI app in front of users quickly and let it answer from your documents, an app builder is a good fit. If agents take actions that cost money or touch real systems, and you need reviewers, operators and finance to trust them, a control plane saves you from building that layer yourself. Check the Dify docs for its current features, since this table only lists what we could confirm.',
                ],
            ],
        ],
        'faqs' => [
            [
                'question' => 'Is FleetQ a Dify alternative?',
                'answer' => 'For running agents in production, yes. FleetQ has agents, crews and a visual workflow builder, built around approvals, budgets and an audit trail. For a no-code app builder with knowledge bases, Dify is the better fit.',
            ],
            [
                'question' => 'Can I self-host FleetQ?',
                'answer' => 'Yes. The FleetQ Community Edition is AGPL-3.0 licensed and runs on your own servers with Docker Compose, free of charge.',
            ],
            [
                'question' => 'Does FleetQ have an MCP server?',
                'answer' => 'Yes. FleetQ ships a built-in MCP server over HTTP/SSE and stdio, so MCP clients can create, run and monitor agents and workflows.',
            ],
        ],
    ],

    'flowise' => [
        'tool_name' => 'Flowise',
        'meta_title' => 'FleetQ vs Flowise: a Flowise alternative compared',
        'meta_description' => 'FleetQ vs Flowise: compare an AI agent control plane with approvals, budget limits, audit trail and an MCP server to a visual LLM flow builder.',
        'keywords' => 'flowise alternative, flowise vs fleetq, fleetq vs flowise, flowise migration, import flowise flows, self-hosted ai agents',
        'h1' => 'FleetQ vs Flowise: from visual LLM flows to governed agents',
        'intro' => [
            'Flowise is a visual builder where you drag nodes onto a canvas to build chatflows and agentflows on top of large language models. FleetQ is a self-hosted control plane for running AI agents in production: every run gets an approval inbox, a budget, an audit trail and access through an MCP server.',
            'If you are looking for a Flowise alternative, or you already have flows you want to keep, this page explains where each tool fits and how to bring your existing flows into FleetQ.',
        ],
        // Optional dated notice shown under the intro. Left empty on purpose:
        // the owner sets a dated line here only after checking the Flowise
        // repository and site on that date.
        'notice' => null,
        'short_answer' => [
            'pick_tool' => 'Pick Flowise if you want a visual canvas to assemble LLM chatflows and agentflows from ready-made nodes, and your main goal is to prototype or ship a chatbot or assistant quickly.',
            'pick_fleetq' => 'Pick FleetQ if you need the operational layer around agents: an approval inbox with SLAs, hard budget limits with auto-pause, a full activity log, bring-your-own keys and an MCP server, all self-hosted under AGPL-3.0.',
        ],
        'table' => $table(),
        'together' => null,
        'sections' => [
            [
                'heading' => 'Where they differ',
                'paragraphs' => [
                    'A flow builder is where you wire up how an LLM app works: which model it calls, which prompt it uses and which documents or tools it can reach. A control plane is where you decide who may run an agent, what it may spend, which of its actions need a person to sign off and how you can audit what happened later. FleetQ focuses on the second job.',
                    'FleetQ still ships its own agents, crews and a visual workflow builder with branching, loops and human tasks, so you can build without writing code. Signals from webhooks, email, RSS and chat tools can start a run, and results go out by email, Slack, Telegram or webhook.',
                ],
            ],
            [
                'heading' => 'What FleetQ adds for agents in production',
                'paragraphs' => [
                    'Risky actions wait in an approval inbox with SLA enforcement and escalation when nobody answers in time. Spend is reserved and settled against a credit ledger with pessimistic locks, so a runaway loop pauses itself instead of draining your provider account, and projects can carry their own budget caps. Every change is written to an activity log that you can search and filter.',
                    'You bring your own keys for Anthropic, OpenAI and Google, or point agents at local models through Ollama, LM Studio or vLLM. The REST API and the built-in MCP server let scripts, IDE assistants and other agents start runs and read results, while the same approval, budget and audit rules apply to every call.',
                ],
            ],
            [
                'heading' => 'How to choose',
                'paragraphs' => [
                    'If you want to sketch an LLM app on a canvas and try it out quickly, a flow builder is a good fit. If agents take actions that cost money or touch real systems, and you need reviewers, operators and finance to trust them, a control plane saves you from building that layer yourself. Check the Flowise docs for its current features, since this table only lists what we could confirm.',
                ],
            ],
        ],
        'migration' => [
            'heading' => 'Moving your Flowise flows to FleetQ',
            'paragraphs' => [
                'You do not have to rebuild your flows by hand. In Flowise, open the chatflow or agentflow, use Export from the settings menu and save the JSON file. FleetQ detects both Agentflow V2 exports and legacy chatflow exports.',
                'Import the file through the REST API with POST /api/v1/workflows/import, with php artisan workflow:import, or with the workflow_import_yaml MCP tool. Each import creates a draft workflow and returns conversion_warnings that list the parts you need to finish by hand, such as conditions, tools, knowledge bases and credentials.',
            ],
        ],
        'faqs' => [
            [
                'question' => 'Can I import my Flowise flows into FleetQ?',
                'answer' => 'Yes. Export the chatflow or agentflow as JSON in Flowise and import it through the REST API, the workflow:import Artisan command or the workflow_import_yaml MCP tool. You get a draft workflow plus conversion_warnings for the parts that need manual follow-up.',
            ],
            [
                'question' => 'Can I self-host FleetQ?',
                'answer' => 'Yes. The FleetQ Community Edition is AGPL-3.0 licensed and runs on your own servers with Docker Compose, free of charge.',
            ],
            [
                'question' => 'Does FleetQ have an MCP server?',
                'answer' => 'Yes. FleetQ ships a built-in MCP server over HTTP/SSE and stdio, so MCP clients can create, run and monitor agents and workflows.',
            ],
        ],
    ],

    'ai-agent-control-planes' => [
        'tool_name' => 'Paperclip, Boomi and Speakeasy',
        'tools' => ['Paperclip', 'Boomi', 'Speakeasy'],
        'meta_title' => 'AI agent control planes: FleetQ, Paperclip, Boomi',
        'meta_description' => 'AI agent control planes compared: how FleetQ, with approvals, budget limits, audit trail and an MCP server, sits next to Paperclip, Boomi and Speakeasy.',
        'keywords' => 'ai agent control plane, agent control plane comparison, paperclip alternative, boomi agent control plane alternative, speakeasy alternative, self-hosted ai agents',
        'h1' => 'AI agent control planes compared: FleetQ, Paperclip, Boomi and Speakeasy',
        'intro' => [
            'More and more tools describe themselves as a control plane for AI agents. The term is useful, but it covers different jobs depending on who uses it. FleetQ is a self-hosted control plane for running AI agents in production: every run gets an approval inbox, a budget, an audit trail and access through an MCP server.',
            'Paperclip, Boomi and Speakeasy each come at agents from their own starting point. This page explains what FleetQ covers, lists the questions worth asking of any control plane, and points you to each vendor\'s own docs for the details of their products.',
        ],
        'short_answer' => [
            'tool_heading' => 'When one of the others is the better fit',
            'pick_tool' => 'Pick Paperclip, Boomi or Speakeasy if its own focus matches the work you need done and it fits the tools your team already uses. Their docs describe their current features best, so read them alongside this page.',
            'pick_fleetq' => 'Pick FleetQ if you need the operational layer around agents: an approval inbox with SLAs, hard budget limits with auto-pause, a full activity log, bring-your-own keys and an MCP server, all self-hosted under AGPL-3.0.',
        ],
        'table' => $multiTable(['Paperclip', 'Boomi', 'Speakeasy']),
        'together' => null,
        'sections' => [
            [
                'heading' => 'What an agent control plane does',
                'paragraphs' => [
                    'An agent control plane sits between the people who are responsible for agents and the agents themselves. It decides who may start an agent, which tools and data an agent may reach, how much it may spend, which of its actions need a person to sign off, and how you can audit what happened later.',
                    'Products that use the label start from different places. Some grew out of integration and API work, some out of developer tooling, and some were built for agents from day one. That is why two tools called a control plane can solve quite different problems, and why a feature table only tells part of the story.',
                ],
            ],
            [
                'heading' => 'What FleetQ covers',
                'paragraphs' => [
                    'Risky actions wait in an approval inbox with SLA enforcement and escalation when nobody answers in time. Spend is reserved and settled against a credit ledger with pessimistic locks, so a runaway loop pauses itself instead of draining your provider account, and projects can carry their own budget caps. Every change is written to an activity log that you can search and filter.',
                    'FleetQ also ships its own agents, crews and a visual workflow builder with branching, loops and human tasks. You bring your own keys for Anthropic, OpenAI and Google, or point agents at local models through Ollama, LM Studio or vLLM. The REST API and the built-in MCP server let scripts, IDE assistants and other agents start runs and read results, while the same approval, budget and audit rules apply to every call.',
                ],
            ],
            [
                'heading' => 'How to choose',
                'paragraphs' => [
                    'Start from the problem you have today. Write down which agents you run, who must approve their actions, what they may spend and who needs to read the audit trail. Then ask each vendor how their product handles those points, and where you can run it.',
                    'This table only lists what we could confirm for FleetQ. Paperclip, Boomi and Speakeasy change over time, so check their own docs for current features, licensing and pricing before you decide.',
                ],
            ],
        ],
        'faqs' => [
            [
                'question' => 'What is an AI agent control plane?',
                'answer' => 'It is the layer that governs agents in production: who may run them, what they may spend, which actions need human approval and how every action is logged for audit.',
            ],
            [
                'question' => 'Can I self-host FleetQ?',
                'answer' => 'Yes. The FleetQ Community Edition is AGPL-3.0 licensed and runs on your own servers with Docker Compose, free of charge.',
            ],
            [
                'question' => 'Does FleetQ have an MCP server?',
                'answer' => 'Yes. FleetQ ships a built-in MCP server over HTTP/SSE and stdio, so MCP clients can create, run and monitor agents and workflows.',
            ],
        ],
    ],

];
