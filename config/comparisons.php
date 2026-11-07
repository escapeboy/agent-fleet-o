<?php

/*
|--------------------------------------------------------------------------
| Comparison pages (/compare/{slug})
|--------------------------------------------------------------------------
|
| Public SEO pages comparing FleetQ with other agent tools. FleetQ cells are
| taken from README.md and the code. Competitor cells may only contain facts
| checked on 'checked_on' on the competitor's own public site or docs;
| anything not checked says "See their docs". No competitor prices.
| 'together' stays null unless the competitor's own docs, checked that day,
| show it can call remote MCP servers.
|
*/

$fleetqTable = [
    'Focus' => 'Control plane for running AI agents in production',
    'Self-hosting' => 'AGPL-3.0, self-hosted, free',
    'Human approvals' => 'Approval inbox with SLA enforcement and escalation',
    'Budget limits' => 'Credit ledger with pessimistic locks, auto-pause on overspend, project budget caps',
    'Audit trail' => 'Searchable activity log of every action',
    'MCP server' => 'Built-in MCP server over HTTP/SSE and stdio',
    'Bring-your-own LLM keys' => 'BYOK for Anthropic, OpenAI and Google, plus local LLMs (Ollama, LM Studio, vLLM)',
    'Pricing model' => 'Community Edition free; Cloud free to start, with paid tiers',
];

$table = fn () => collect($fleetqTable)
    ->map(fn (string $fleetq, string $label) => ['label' => $label, 'fleetq' => $fleetq, 'tool' => 'See their docs'])
    ->values()
    ->all();

$sharedSections = fn (string $tool) => [
    [
        'heading' => 'What FleetQ adds around your agents',
        'paragraphs' => [
            'FleetQ is a self-hosted control plane. You define agents with a role, a goal and a set of tools, group them into crews, and connect them into workflows on a visual DAG builder. Signals from webhooks, RSS, email, Slack or Telegram can start a run, and every stage is queued, retried and tracked, so a failed step does not silently disappear.',
            'Each run reserves credits from a ledger before it calls a model and settles the real cost afterwards. The ledger uses pessimistic locks, so parallel runs cannot overspend a budget, and a project that hits its cap is paused automatically instead of running up a bill overnight.',
            'Sensitive steps can stop at an approval gate. The request lands in an approval inbox with an SLA, and if nobody answers in time it escalates. Every approval, rejection, configuration change and agent action is written to an activity log that you can search and filter later.',
        ],
    ],
    [
        'heading' => 'Bring your own models and tools',
        'paragraphs' => [
            'FleetQ talks to Anthropic, OpenAI and Google with your own API keys, and to local models through Ollama, LM Studio, vLLM or any OpenAI-compatible endpoint. You can prototype on a local model at no API cost and switch the same agent to a hosted provider for production.',
            'The built-in MCP server exposes the platform to any MCP client over HTTP/SSE or stdio, so an assistant in your editor can create agents, start runs or answer approvals. Agents can also use external MCP servers, built-in tools and your own integrations as tools.',
        ],
    ],
    [
        'heading' => "How to evaluate FleetQ and {$tool}",
        'paragraphs' => [
            "Pick one real workflow, ideally one that touches money, customers or production systems. Build it in both tools and write down what happens when a model call fails, when a run would exceed its budget, when a human needs to sign off, and when someone asks a month later why the agent did what it did. Those answers usually decide the choice faster than a feature list.",
            "Product details change quickly in this space, so check {$tool}'s own documentation for its current features rather than relying on any third-party table, including ours.",
        ],
    ],
];

return [

    'checked_on' => '2026-11-07',

    'langgraph' => [
        'tool_name' => 'LangGraph',
        'meta_title' => 'FleetQ vs LangGraph: agent control plane compared',
        'meta_description' => 'FleetQ vs LangGraph: an agent framework for writing agent logic in code versus a self-hosted control plane with approvals, budgets and audit.',
        'keywords' => 'langgraph alternative, fleetq vs langgraph, langgraph vs fleetq, ai agent control plane, self-hosted agent platform',
        'h1' => 'FleetQ vs LangGraph: code-first agent framework or production control plane',
        'intro' => [
            'LangGraph is a framework for building agents as graphs in code. FleetQ is an open-source control plane for running agents in production, with approvals, budgets and an audit trail built in. They solve different layers of the same problem, and many teams need only one of them.',
            'This page compares them honestly. Where we could not confirm a detail about LangGraph on its own documentation, the table says so instead of guessing.',
        ],
        'short_answer' => [
            'pick_tool' => 'Pick LangGraph if your team wants to write agent logic in code and fine-tune every step of the graph. It has a larger ecosystem and community, and comes from a more mature project family.',
            'pick_fleetq' => 'Pick FleetQ if you need a self-hosted place to run, approve, budget and audit agents across a team, with a UI, a REST API and an MCP server instead of only a library.',
        ],
        'table' => $table(),
        'together' => null,
        'sections' => $sharedSections('LangGraph'),
        'faqs' => [
            [
                'question' => 'Is FleetQ a LangGraph alternative?',
                'answer' => 'Partly. LangGraph is a framework you use to write agent logic in code. FleetQ is a platform that runs agents and adds approvals, budget limits and an audit trail. If you mainly need the operations layer, FleetQ can replace a custom build around a framework.',
            ],
            [
                'question' => 'Is FleetQ free to self-host?',
                'answer' => 'Yes. The FleetQ Community Edition is licensed under AGPL-3.0 and you can run it on your own infrastructure for free. A hosted Cloud version is free to start, with paid tiers.',
            ],
            [
                'question' => 'Can I use my own LLM keys with FleetQ?',
                'answer' => 'Yes. FleetQ supports your own keys for Anthropic, OpenAI and Google, and local models through Ollama, LM Studio, vLLM or any OpenAI-compatible endpoint.',
            ],
        ],
    ],

    'crewai' => [
        'tool_name' => 'CrewAI',
        'meta_title' => 'FleetQ vs CrewAI: multi-agent platforms compared',
        'meta_description' => 'FleetQ vs CrewAI: a multi-agent framework versus a self-hosted control plane with human approvals, budget limits and a full audit trail.',
        'keywords' => 'crewai alternative, fleetq vs crewai, crewai vs fleetq, multi-agent platform, self-hosted ai agents',
        'h1' => 'FleetQ vs CrewAI: multi-agent framework or production control plane',
        'intro' => [
            'CrewAI is a framework for orchestrating teams of role-based AI agents. FleetQ also groups agents into crews, but it is an open-source control plane for running them in production, with approvals, budgets and an audit trail built in.',
            'This page compares them honestly. Where we could not confirm a detail about CrewAI on its own documentation, the table says so instead of guessing.',
        ],
        'short_answer' => [
            'pick_tool' => 'Pick CrewAI if your team works in Python and wants to define crews in code. It has a larger ecosystem and community, and more mature tutorials and examples.',
            'pick_fleetq' => 'Pick FleetQ if you need a self-hosted place to run, approve, budget and audit agent crews across a team, with a UI, a REST API and an MCP server instead of only a library.',
        ],
        'table' => $table(),
        'together' => null,
        'sections' => $sharedSections('CrewAI'),
        'faqs' => [
            [
                'question' => 'Is FleetQ a CrewAI alternative?',
                'answer' => 'For running crews in production, yes. FleetQ lets you define agents with a role and goal, group them into crews and run them with approvals, budget limits and an audit trail. If you prefer defining crews in Python code, CrewAI may suit you better.',
            ],
            [
                'question' => 'Is FleetQ free to self-host?',
                'answer' => 'Yes. The FleetQ Community Edition is licensed under AGPL-3.0 and you can run it on your own infrastructure for free. A hosted Cloud version is free to start, with paid tiers.',
            ],
            [
                'question' => 'Does FleetQ have an MCP server?',
                'answer' => 'Yes. FleetQ ships a built-in MCP server over HTTP/SSE and stdio, so MCP clients can manage agents, crews, runs and approvals.',
            ],
        ],
    ],

    'n8n' => [
        'tool_name' => 'n8n',
        'meta_title' => 'FleetQ vs n8n: AI agent control plane compared',
        'meta_description' => 'FleetQ vs n8n: a general workflow automation tool versus a self-hosted control plane built for AI agents, with approvals, budgets and audit.',
        'keywords' => 'n8n alternative, fleetq vs n8n, n8n vs fleetq, ai agent workflow automation, self-hosted ai agents',
        'h1' => 'FleetQ vs n8n: general workflow automation or an AI agent control plane',
        'intro' => [
            'n8n is a workflow automation tool for connecting apps and services. FleetQ is an open-source control plane built specifically for running AI agents in production, with approvals, budgets and an audit trail built in.',
            'This page compares them honestly. Where we could not confirm a detail about n8n on its own documentation, the table says so instead of guessing.',
        ],
        'short_answer' => [
            'pick_tool' => 'Pick n8n if most of your work is connecting apps and moving data, with AI as one step among many. It has a larger integration ecosystem and community, and is the more mature automation product.',
            'pick_fleetq' => 'Pick FleetQ if AI agents are the core of the work and you need a self-hosted place to run, approve, budget and audit them, with a UI, a REST API and an MCP server.',
        ],
        'table' => $table(),
        'together' => null,
        'sections' => $sharedSections('n8n'),
        'faqs' => [
            [
                'question' => 'Is FleetQ an n8n alternative?',
                'answer' => 'For AI agent workloads, yes. FleetQ is built around agents, crews, budgets and approvals. For general app-to-app automation with many connectors, n8n may be the better fit.',
            ],
            [
                'question' => 'Is FleetQ free to self-host?',
                'answer' => 'Yes. The FleetQ Community Edition is licensed under AGPL-3.0 and you can run it on your own infrastructure for free. A hosted Cloud version is free to start, with paid tiers.',
            ],
            [
                'question' => 'How does FleetQ stop agents from overspending?',
                'answer' => 'Every run reserves credits from a ledger with pessimistic locks before it calls a model. When a project reaches its budget cap, FleetQ pauses the work automatically.',
            ],
        ],
    ],

];
