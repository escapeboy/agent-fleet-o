<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Page Help Content
    |--------------------------------------------------------------------------
    |
    | Contextual help content for each page, keyed by route name.
    | Each entry supports: title, description, steps, tips, prerequisites, related.
    |
    | Prerequisites can be plain strings or arrays with 'label' and 'route' keys
    | to render as navigable links.
    |
    */

    // ── Dashboard ──────────────────────────────────────────────────────

    'dashboard' => [
        'title' => 'Dashboard',
        'description' => 'Your mission control overview. See active projects, running experiments, pending approvals, and budget status at a glance.',
        'steps' => [
            'Review the KPI cards at the top for system-wide metrics',
            'Check "Active Experiments" for running pipelines',
            'Monitor "Pending Approvals" to unblock waiting tasks',
            'Review budget consumption and forecast',
        ],
        'tips' => [
            'Dashboard data refreshes automatically every 60 seconds',
            'Click any metric card to navigate to the detail view',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Projects', 'route' => 'projects.index'],
            ['label' => 'Experiments', 'route' => 'experiments.index'],
            ['label' => 'Approvals', 'route' => 'approvals.index'],
        ],
    ],

    // ── Experiments ────────────────────────────────────────────────────

    'experiments.index' => [
        'title' => 'Experiments',
        'description' => 'Experiments are the core execution unit. Each experiment flows through a pipeline from Draft to Completed, with AI agents performing work at each stage.',
        'steps' => [
            'Click "New Experiment" to create a draft',
            'Set the hypothesis, track (growth/retention/engagement), and goal',
            'The experiment auto-progresses through scoring, planning, building, and execution',
            'Review results and metrics when the experiment completes',
        ],
        'tips' => [
            'Use "Retry from Step" to resume failed experiments without starting over',
            'Experiments can be paused at any active state',
            'Filter by status or track to find specific experiments',
        ],
        'prerequisites' => [
            ['label' => 'Configure at least one AI Agent', 'route' => 'agents.index'],
            ['label' => 'Set up AI provider keys', 'route' => 'team.settings'],
        ],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Workflows', 'route' => 'workflows.index'],
            ['label' => 'Projects', 'route' => 'projects.index'],
        ],
    ],

    'experiments.show' => [
        'title' => 'Experiment Detail',
        'description' => 'View the full lifecycle of an experiment — its current stage, execution logs, transitions, outbound actions, metrics, and artifacts.',
        'steps' => [
            'Review the current stage and status at the top',
            'Check the timeline tab for stage-by-stage progression',
            'View execution logs for AI agent outputs',
            'Inspect artifacts generated during execution',
        ],
        'tips' => [
            'Use the action buttons to pause, resume, retry, or kill the experiment',
            'The transitions tab shows every state change with timestamps',
            'Outbound tab shows all messages sent during execution',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Experiments', 'route' => 'experiments.index'],
            ['label' => 'Approvals', 'route' => 'approvals.index'],
        ],
    ],

    // ── Agents ─────────────────────────────────────────────────────────

    'agents.index' => [
        'title' => 'Agents',
        'description' => 'AI agents are the workers that execute tasks. Each agent has a role, goal, backstory, and assigned skills that determine how it handles work.',
        'steps' => [
            'Click "New Agent" to create one from scratch, or use a template',
            'Give the agent a clear role and goal (e.g. "Content Writer" with goal "Write blog posts")',
            'Assign skills that the agent can use during execution',
            'Optionally attach tools (MCP servers, bash, browser) for extended capabilities',
        ],
        'tips' => [
            'Agents can be disabled without deleting them',
            'Each agent has a health status — check it if executions fail',
            'Use the template gallery for pre-built agent configurations',
        ],
        'prerequisites' => [
            ['label' => 'Set up AI provider keys in Team Settings', 'route' => 'team.settings'],
        ],
        'related' => [
            ['label' => 'Skills', 'route' => 'skills.index'],
            ['label' => 'Tools', 'route' => 'tools.index'],
            ['label' => 'Crews', 'route' => 'crews.index'],
        ],
    ],

    'agents.create' => [
        'title' => 'Create Agent',
        'description' => 'Define a new AI agent by setting its identity (role, goal, backstory), choosing an LLM provider, and assigning skills and tools.',
        'steps' => [
            'Fill in the agent name, role, and goal',
            'Write a backstory to give the agent context about its expertise',
            'Select an LLM provider and model',
            'Assign skills and tools the agent should use',
        ],
        'tips' => [
            'Specific roles and goals produce better results than vague ones',
            'The backstory helps the LLM understand the agent\'s persona',
            'You can always edit the agent later to refine its configuration',
        ],
        'prerequisites' => [
            ['label' => 'Set up AI provider keys in Team Settings', 'route' => 'team.settings'],
        ],
        'related' => [
            ['label' => 'Agent Templates', 'route' => 'agents.templates'],
            ['label' => 'All Agents', 'route' => 'agents.index'],
        ],
    ],

    'agents.show' => [
        'title' => 'Agent Detail',
        'description' => 'View and manage an agent\'s configuration, assigned skills, tools, execution history, and health status.',
        'steps' => [
            'Review the agent\'s role, goal, and backstory',
            'Check assigned skills and tools',
            'View recent executions and their outcomes',
            'Monitor health status for any issues',
        ],
        'tips' => [
            'Use the edit button to update the agent\'s configuration',
            'The execution history shows token usage and costs per run',
            'Disable an agent to prevent it from being used in new experiments',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Agents', 'route' => 'agents.index'],
            ['label' => 'Skills', 'route' => 'skills.index'],
            ['label' => 'Tools', 'route' => 'tools.index'],
        ],
    ],

    'agents.templates' => [
        'title' => 'Agent Templates',
        'description' => 'Browse pre-built agent configurations. Templates provide ready-to-use agents with optimized roles, goals, and skill assignments.',
        'steps' => [
            'Browse the template gallery to find an agent that fits your use case',
            'Click a template to preview its configuration',
            'Click "Use Template" to create a new agent based on the template',
            'Customize the agent after creation to fit your specific needs',
        ],
        'tips' => [
            'Templates are starting points — always customize the goal for your context',
            'You can create agents from scratch if no template fits',
        ],
        'prerequisites' => [
            ['label' => 'Set up AI provider keys', 'route' => 'team.settings'],
        ],
        'related' => [
            ['label' => 'All Agents', 'route' => 'agents.index'],
            ['label' => 'Create Agent', 'route' => 'agents.create'],
        ],
    ],

    // ── Skills ─────────────────────────────────────────────────────────

    'skills.index' => [
        'title' => 'Skills',
        'description' => 'Skills are reusable capabilities that agents use during execution. They can be LLM prompts, connector integrations, business rules, or hybrid combinations.',
        'steps' => [
            'Click "New Skill" to create a reusable capability',
            'Choose the skill type: LLM, Connector, Rule, or Hybrid',
            'Define the input/output schema for structured data flow',
            'Assign the skill to one or more agents',
        ],
        'tips' => [
            'Skills are versioned — you can roll back to a previous version',
            'Guardrail skills run automatically to validate agent outputs',
            'Check the Marketplace for community-shared skills',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Marketplace', 'route' => 'app.marketplace.index'],
        ],
    ],

    'skills.create' => [
        'title' => 'Create Skill',
        'description' => 'Define a new skill by setting its type, prompt template, input/output schema, and risk level.',
        'steps' => [
            'Name the skill and choose a type (LLM, Connector, Rule, Hybrid)',
            'Write the prompt template with placeholders for dynamic inputs',
            'Define the JSON schema for inputs and outputs',
            'Set the risk level and execution type',
        ],
        'tips' => [
            'Use {{variable}} syntax in prompt templates for dynamic values',
            'Start with a simple prompt and iterate after testing',
            'Schema validation ensures agents produce structured outputs',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Skills', 'route' => 'skills.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    'skills.show' => [
        'title' => 'Skill Detail',
        'description' => 'View and manage a skill\'s configuration, version history, and execution statistics.',
        'steps' => [
            'Review the skill\'s prompt template and schema',
            'Check the version history for recent changes',
            'View execution statistics and success rates',
            'Edit the skill to improve its prompts or schema',
        ],
        'tips' => [
            'Each edit creates a new version — previous versions are preserved',
            'Execution stats help identify skills that need prompt refinement',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Skills', 'route' => 'skills.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    // ── Tools ──────────────────────────────────────────────────────────

    'tools.index' => [
        'title' => 'Tools',
        'description' => 'Tools extend agent capabilities beyond text generation. They include MCP servers (local or remote), built-in tools (bash, filesystem, browser), and custom integrations.',
        'steps' => [
            'Click "New Tool" to register a tool',
            'Choose the tool type: MCP (stdio/HTTP) or Built-in',
            'Configure the transport settings (command, URL, or kind)',
            'Assign the tool to agents that need it',
        ],
        'tips' => [
            'MCP stdio tools run locally — ideal for file operations and CLI tools',
            'MCP HTTP tools connect to remote servers — great for SaaS integrations',
            'Tools are disabled by default until you configure and activate them',
        ],
        'prerequisites' => [
            ['label' => 'Create at least one agent to assign tools to', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Credentials', 'route' => 'credentials.index'],
        ],
    ],

    'tools.create' => [
        'title' => 'Create Tool',
        'description' => 'Register a new tool by specifying its type, transport configuration, and optional credentials.',
        'steps' => [
            'Name the tool and choose a type (MCP stdio, MCP HTTP, or Built-in)',
            'Configure the transport (command path, URL, or built-in kind)',
            'Add any required environment variables or credentials',
            'Save and assign to agents',
        ],
        'tips' => [
            'For MCP stdio, provide the full command path (e.g., npx -y @modelcontextprotocol/server-filesystem)',
            'For MCP HTTP, provide the SSE endpoint URL',
            'Test the tool connection before assigning to agents',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Tools', 'route' => 'tools.index'],
            ['label' => 'Credentials', 'route' => 'credentials.index'],
        ],
    ],

    'tools.show' => [
        'title' => 'Tool Detail',
        'description' => 'View and manage a tool\'s configuration, connection status, and which agents use it.',
        'steps' => [
            'Review the tool\'s transport configuration',
            'Check which agents have this tool assigned',
            'Test the connection to verify the tool works',
            'Edit settings or credentials as needed',
        ],
        'tips' => [
            'If a tool shows errors, check its transport configuration and credentials',
            'You can disable a tool without removing it from agents',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Tools', 'route' => 'tools.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    // ── Credentials ────────────────────────────────────────────────────

    'credentials.index' => [
        'title' => 'Credentials',
        'description' => 'Manage encrypted credentials for external services. Credentials are injected into agent executions and tool configurations securely.',
        'steps' => [
            'Click "New Credential" to store a new secret',
            'Choose the type: API Key, OAuth2, Bearer Token, Basic Auth, or Custom',
            'Enter the credential value — it will be encrypted at rest',
            'Link credentials to projects or tools that need them',
        ],
        'tips' => [
            'Credentials are encrypted and never shown in full after creation',
            'Set expiry dates for credentials that rotate periodically',
            'Use the rotate action to update secrets without downtime',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Tools', 'route' => 'tools.index'],
            ['label' => 'Projects', 'route' => 'projects.index'],
            ['label' => 'Team Settings', 'route' => 'team.settings'],
        ],
    ],

    'credentials.create' => [
        'title' => 'Create Credential',
        'description' => 'Store a new encrypted credential for use in agent executions, tools, or project configurations.',
        'steps' => [
            'Name the credential descriptively (e.g. "GitHub API Token")',
            'Select the credential type',
            'Enter the secret value',
            'Optionally set an expiry date',
        ],
        'tips' => [
            'Use descriptive names to easily identify credentials later',
            'The secret is encrypted immediately — you won\'t be able to view it again',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Credentials', 'route' => 'credentials.index'],
        ],
    ],

    // ── Crews ──────────────────────────────────────────────────────────

    'crews.index' => [
        'title' => 'Crews',
        'description' => 'Crews are multi-agent teams that collaborate on complex tasks. Agents in a crew work together using sequential or hierarchical processes.',
        'steps' => [
            'Click "New Crew" to assemble a team of agents',
            'Add agents as crew members and assign roles',
            'Choose a process type: Sequential (one after another) or Hierarchical (manager delegates)',
            'Execute the crew with a goal to see agents collaborate',
        ],
        'tips' => [
            'Sequential processes work best for linear workflows',
            'Hierarchical processes let a manager agent coordinate work',
            'Each crew execution produces artifacts from each agent\'s work',
        ],
        'prerequisites' => [
            ['label' => 'Create at least two agents for the crew', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Projects', 'route' => 'projects.index'],
        ],
    ],

    'crews.create' => [
        'title' => 'Create Crew',
        'description' => 'Assemble a multi-agent crew by selecting agents, assigning roles, and choosing a collaboration process.',
        'steps' => [
            'Name the crew and set its goal',
            'Add agents and assign each a role (worker or manager)',
            'Choose the process type (sequential or hierarchical)',
            'Save and execute to test the crew',
        ],
        'tips' => [
            'A crew needs at least two agents to be useful',
            'The crew goal guides all agents toward a shared objective',
            'You can adjust member roles after creation',
        ],
        'prerequisites' => [
            ['label' => 'Create agents to add as crew members', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'All Crews', 'route' => 'crews.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    'crews.show' => [
        'title' => 'Crew Detail',
        'description' => 'View a crew\'s composition, configuration, and execution history.',
        'steps' => [
            'Review the crew members and their roles',
            'Check recent executions and their outcomes',
            'Edit the crew to add/remove members or change the process',
            'Start a new execution with a specific goal',
        ],
        'tips' => [
            'Execution history shows how agents collaborated and what they produced',
            'You can rerun a crew with a different goal without changing its composition',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Crews', 'route' => 'crews.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    // ── Projects ───────────────────────────────────────────────────────

    'projects.index' => [
        'title' => 'Projects',
        'description' => 'Projects group related experiments and workflows. They can be one-shot (run once) or continuous (scheduled recurring runs).',
        'steps' => [
            'Click "New Project" to create one',
            'Choose the type: One-shot (single run) or Continuous (scheduled)',
            'Assign a workflow that defines the execution pipeline',
            'For continuous projects, set a schedule (hourly, daily, weekly, etc.)',
        ],
        'tips' => [
            'Continuous projects run automatically on schedule — monitor them from the dashboard',
            'Set budget limits to prevent runaway costs on continuous projects',
            'Use milestones to track project progress toward goals',
        ],
        'prerequisites' => [
            ['label' => 'Create a workflow to use as the project pipeline', 'route' => 'workflows.index'],
            ['label' => 'Configure at least one agent', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'Workflows', 'route' => 'workflows.index'],
            ['label' => 'Experiments', 'route' => 'experiments.index'],
        ],
    ],

    'projects.create' => [
        'title' => 'Create Project',
        'description' => 'Set up a new project by defining its type, workflow, schedule, budget, and input data.',
        'steps' => [
            'Name the project and describe its objective',
            'Select the project type (one-shot or continuous)',
            'Choose a workflow to execute',
            'For continuous projects, configure the schedule and overlap policy',
        ],
        'tips' => [
            'Start with a one-shot project to test your workflow before scheduling',
            'The overlap policy controls what happens when a new run starts while one is already active',
            'Set a budget ceiling to prevent unexpected costs',
        ],
        'prerequisites' => [
            ['label' => 'Create a workflow first', 'route' => 'workflows.create'],
        ],
        'related' => [
            ['label' => 'All Projects', 'route' => 'projects.index'],
            ['label' => 'Workflows', 'route' => 'workflows.index'],
        ],
    ],

    'projects.show' => [
        'title' => 'Project Detail',
        'description' => 'View a project\'s status, runs, milestones, activity timeline, and budget consumption.',
        'steps' => [
            'Review the project status and current run at the top',
            'Check the runs table for execution history',
            'View the activity timeline for all events',
            'Monitor budget usage and forecasts',
        ],
        'tips' => [
            'Use pause/resume to temporarily stop a continuous project',
            'The kanban view (if available) shows milestone progress visually',
            'Click any run to see its detailed execution log',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Projects', 'route' => 'projects.index'],
            ['label' => 'Workflows', 'route' => 'workflows.index'],
        ],
    ],

    // ── Workflows ──────────────────────────────────────────────────────

    'workflows.index' => [
        'title' => 'Workflows',
        'description' => 'Workflows are reusable DAG (directed acyclic graph) templates that define execution pipelines. They connect agents, conditions, human tasks, and other nodes.',
        'steps' => [
            'Click "New Workflow" to open the visual builder',
            'Drag and connect nodes to build your pipeline',
            'Add agent nodes for AI-powered steps, conditional nodes for branching',
            'Validate the workflow before activating it',
        ],
        'tips' => [
            'Start simple — a linear workflow with 2-3 agent nodes is a good first step',
            'Use conditional nodes to branch based on previous step outputs',
            'Human task nodes pause execution for manual review or input',
            'You can generate workflows from natural language descriptions',
        ],
        'prerequisites' => [
            ['label' => 'Create agents to use in workflow nodes', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Projects', 'route' => 'projects.index'],
            ['label' => 'Experiments', 'route' => 'experiments.index'],
        ],
    ],

    'workflows.create' => [
        'title' => 'Workflow Builder',
        'description' => 'Visual editor for building workflow DAGs. Connect start, agent, conditional, human task, switch, fork, and loop nodes to define execution pipelines.',
        'steps' => [
            'Every workflow starts with a Start node and ends with an End node',
            'Add Agent nodes and assign an agent to each one',
            'Connect nodes with edges to define the execution flow',
            'Click "Validate" to check for graph errors before saving',
        ],
        'tips' => [
            'Use the "Generate from Prompt" button to create workflows from natural language',
            'Switch nodes route execution based on expressions (like if/else)',
            'Dynamic Fork nodes split execution into parallel branches',
            'Do-While nodes repeat until a condition is met',
        ],
        'prerequisites' => [
            ['label' => 'Create agents to assign to workflow nodes', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'All Workflows', 'route' => 'workflows.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    'workflows.show' => [
        'title' => 'Workflow Detail',
        'description' => 'View a workflow\'s graph structure, node configuration, and usage across projects and experiments.',
        'steps' => [
            'Review the workflow graph visualization',
            'Check which projects use this workflow',
            'View the node configuration details',
            'Use actions to edit, duplicate, or activate the workflow',
        ],
        'tips' => [
            'Duplicate a workflow to create a variant without affecting the original',
            'Archived workflows can\'t be used in new projects but remain visible',
            'Use the cost estimator to predict execution costs before running',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Workflows', 'route' => 'workflows.index'],
            ['label' => 'Projects', 'route' => 'projects.index'],
        ],
    ],

    // ── Marketplace ────────────────────────────────────────────────────

    'app.marketplace.index' => [
        'title' => 'Marketplace',
        'description' => 'Browse and install community-shared skills, agents, and workflows. Publish your own creations for others to use.',
        'steps' => [
            'Browse listings by category or search by keyword',
            'Click a listing to see details, reviews, and installation instructions',
            'Click "Install" to add a listing to your team',
            'Use the "Publish" button to share your own skills or agents',
        ],
        'tips' => [
            'Installed items are copies — updates to the original won\'t affect your version',
            'Leave reviews to help the community find quality listings',
            'Check the install count and reviews before installing',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Skills', 'route' => 'skills.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    // ── Approvals ──────────────────────────────────────────────────────

    'approvals.index' => [
        'title' => 'Approvals',
        'description' => 'Review and decide on pending approval requests and human tasks. Experiments and workflows pause here until a human makes a decision.',
        'steps' => [
            'Review pending requests in the inbox',
            'Click a request to see the full context and AI-generated content',
            'Approve to continue the pipeline, or reject to send it back',
            'For human tasks, fill in the required form fields and submit',
        ],
        'tips' => [
            'Stale approvals expire automatically — don\'t leave them pending too long',
            'Human tasks from workflows may include custom forms for structured input',
            'Check the experiment or project detail for full context before deciding',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Experiments', 'route' => 'experiments.index'],
            ['label' => 'Projects', 'route' => 'projects.index'],
        ],
    ],

    // ── Memory ─────────────────────────────────────────────────────────

    'memory.index' => [
        'title' => 'Memory Browser',
        'description' => 'Browse and search the team\'s semantic memory. Memory entries are created during agent executions and can be searched by similarity.',
        'steps' => [
            'Use the search bar to find memories by keyword or semantic similarity',
            'Browse recent memories sorted by creation date',
            'Click a memory to view its full content and metadata',
            'Delete outdated or incorrect memories as needed',
        ],
        'tips' => [
            'Semantic search finds conceptually similar memories, not just keyword matches',
            'Memory is used by agents to learn from past executions',
            'Each memory includes metadata about its source (experiment, agent, etc.)',
        ],
        'prerequisites' => [
            'Run at least one experiment or crew execution to generate memories',
        ],
        'related' => [
            ['label' => 'Experiments', 'route' => 'experiments.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    // ── Signals ────────────────────────────────────────────────────────

    'signals.connectors' => [
        'title' => 'Signal Connectors',
        'description' => 'Manage inbound signal sources. Connectors poll external services (RSS, webhooks, Telegram, etc.) and convert incoming data into signals.',
        'steps' => [
            'Click "Add Connector" to set up a new signal source',
            'Choose the connector type (Webhook, RSS, Telegram, etc.)',
            'Configure the connector settings (URL, polling interval, etc.)',
            'Signals will appear automatically once the connector is active',
        ],
        'tips' => [
            'Webhook connectors receive data pushed from external services',
            'RSS connectors poll feeds at regular intervals',
            'Use trigger rules to automatically act on incoming signals',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Trigger Rules', 'route' => 'triggers.index'],
            ['label' => 'Entities', 'route' => 'signals.entities'],
            ['label' => 'Bindings', 'route' => 'signals.bindings'],
        ],
    ],

    'signals.entities' => [
        'title' => 'Entity Browser',
        'description' => 'Browse all signals ingested by connectors. Each signal is a structured data event from an external source.',
        'steps' => [
            'Browse signals sorted by ingestion date',
            'Filter by source type or connector',
            'Click a signal to view its full payload',
            'Check if trigger rules matched the signal',
        ],
        'tips' => [
            'Signals are the raw events — trigger rules decide what to do with them',
            'Each signal links to its source connector for traceability',
        ],
        'prerequisites' => [
            ['label' => 'Set up a signal connector first', 'route' => 'signals.connectors'],
        ],
        'related' => [
            ['label' => 'Connectors', 'route' => 'signals.connectors'],
            ['label' => 'Trigger Rules', 'route' => 'triggers.index'],
        ],
    ],

    'signals.bindings' => [
        'title' => 'Connector Bindings',
        'description' => 'Manage bindings between signal connectors and their chat/channel associations. Bindings control which conversations receive which signals.',
        'steps' => [
            'View existing bindings between connectors and channels',
            'Approve or reject pending binding requests',
            'Delete bindings that are no longer needed',
        ],
        'tips' => [
            'Bindings are created automatically when a connector first receives a message from a new channel',
            'Telegram bots create bindings per chat conversation',
        ],
        'prerequisites' => [
            ['label' => 'Set up a signal connector', 'route' => 'signals.connectors'],
        ],
        'related' => [
            ['label' => 'Connectors', 'route' => 'signals.connectors'],
        ],
    ],

    // ── Contacts ───────────────────────────────────────────────────────

    'contacts.index' => [
        'title' => 'Contacts',
        'description' => 'View unified contact identities across all communication channels. Contacts are automatically created when signals arrive from identifiable sources.',
        'steps' => [
            'Browse contacts sorted by recent activity',
            'Search by name, email, or channel identifier',
            'Click a contact to view their full cross-channel identity',
            'Merge duplicate contacts that represent the same person',
        ],
        'tips' => [
            'Contacts are created automatically — no manual entry needed',
            'Merging contacts combines all channel identities into one profile',
            'Each contact shows their signal history across all channels',
        ],
        'prerequisites' => [
            ['label' => 'Set up signal connectors to start receiving contact data', 'route' => 'signals.connectors'],
        ],
        'related' => [
            ['label' => 'Signal Connectors', 'route' => 'signals.connectors'],
        ],
    ],

    // ── Triggers ───────────────────────────────────────────────────────

    'triggers.index' => [
        'title' => 'Trigger Rules',
        'description' => 'Automate responses to incoming signals. Trigger rules evaluate conditions on signals and automatically start project runs when conditions match.',
        'steps' => [
            'Click "New Trigger Rule" to create an automation',
            'Define conditions that signals must match (e.g., source_type = "rss", payload contains "urgent")',
            'Map signal fields to project input data',
            'Select which project to trigger when conditions match',
        ],
        'tips' => [
            'Cooldown prevents the same trigger from firing too frequently',
            'Use the test action to simulate a signal and check if your conditions match',
            'Triggers respect max_concurrent settings to prevent overload',
        ],
        'prerequisites' => [
            ['label' => 'Set up a signal connector to receive events', 'route' => 'signals.connectors'],
            ['label' => 'Create a project to trigger', 'route' => 'projects.index'],
        ],
        'related' => [
            ['label' => 'Signal Connectors', 'route' => 'signals.connectors'],
            ['label' => 'Projects', 'route' => 'projects.index'],
        ],
    ],

    // ── Integrations ───────────────────────────────────────────────────

    'integrations.index' => [
        'title' => 'Integrations',
        'description' => 'Connect external services to the platform via OAuth or API credentials. Integrations enable agents and skills to interact with third-party tools.',
        'steps' => [
            'Browse available integrations',
            'Click "Connect" to authenticate via OAuth or enter API credentials',
            'Once connected, agents and skills can use the integration',
            'Manage connected integrations and revoke access when needed',
        ],
        'tips' => [
            'OAuth integrations handle token refresh automatically',
            'Some integrations require specific plan tiers',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Credentials', 'route' => 'credentials.index'],
            ['label' => 'Tools', 'route' => 'tools.index'],
        ],
    ],

    // ── Metrics ────────────────────────────────────────────────────────

    'metrics.models' => [
        'title' => 'Model Comparison',
        'description' => 'Compare AI model performance across your executions. See token usage, costs, latency, and success rates for each LLM provider and model.',
        'steps' => [
            'Review the comparison table for all models used',
            'Sort by cost, latency, or success rate',
            'Identify the best model for each type of task',
            'Use insights to optimize agent model assignments',
        ],
        'tips' => [
            'Lower cost per token doesn\'t always mean better — check success rates too',
            'Latency matters for real-time tasks but less for batch processing',
            'Aggregate data improves with more executions',
        ],
        'prerequisites' => [
            'Run experiments or crew executions to generate comparison data',
        ],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Experiments', 'route' => 'experiments.index'],
        ],
    ],

    // ── Admin & Settings ───────────────────────────────────────────────

    'team.settings' => [
        'title' => 'Team Settings',
        'description' => 'Configure your team\'s AI provider API keys, manage team members, and generate API tokens for external integrations.',
        'steps' => [
            'Add your AI provider API keys (Anthropic, OpenAI, Google)',
            'Manage team members and their roles',
            'Generate API tokens for programmatic access',
            'Configure team-level preferences and defaults',
        ],
        'tips' => [
            'At least one AI provider key is needed for agent execution',
            'API tokens inherit the generating user\'s permissions',
            'Team owner has full access — admin can manage most settings',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Credentials', 'route' => 'credentials.index'],
        ],
    ],

    'health' => [
        'title' => 'System Health',
        'description' => 'Monitor the health of all platform components — database, Redis, queue workers, AI providers, and scheduled tasks.',
        'steps' => [
            'Review the health status of each component',
            'Green = healthy, yellow = degraded, red = down',
            'Click a component for detailed diagnostics',
            'Check queue backlogs for processing delays',
        ],
        'tips' => [
            'If AI providers show unhealthy, check your API keys in Team Settings',
            'Queue backlog indicates processing delays — Horizon manages workers automatically',
            'Redis and PostgreSQL health are critical for all platform operations',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Team Settings', 'route' => 'team.settings'],
        ],
    ],

    'audit' => [
        'title' => 'Audit Log',
        'description' => 'Full audit trail of all actions taken on the platform — experiment transitions, approvals, budget events, agent changes, and more.',
        'steps' => [
            'Browse events sorted by timestamp (newest first)',
            'Filter by event type, user, or entity',
            'Click an entry to view the full event details',
            'Export audit data for compliance reporting',
        ],
        'tips' => [
            'Audit retention depends on your plan tier',
            'Each entry includes who, what, when, and the before/after state',
            'Use the audit log to investigate unexpected behavior or security concerns',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Dashboard', 'route' => 'dashboard'],
        ],
    ],

    'notifications.index' => [
        'title' => 'Notifications',
        'description' => 'View all platform notifications — experiment completions, approval requests, budget alerts, and system events.',
        'steps' => [
            'Review unread notifications at the top',
            'Click a notification to navigate to the related entity',
            'Mark notifications as read individually or in bulk',
            'Configure notification preferences to control what you receive',
        ],
        'tips' => [
            'The bell icon in the header shows unread count',
            'Email notifications are sent for critical events (failures, approvals)',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Notification Preferences', 'route' => 'notifications.preferences'],
        ],
    ],

    'notifications.preferences' => [
        'title' => 'Notification Preferences',
        'description' => 'Control which notifications you receive and how they are delivered (in-app, email, or both).',
        'steps' => [
            'Review each notification category',
            'Toggle in-app and email delivery per category',
            'Save your preferences',
        ],
        'tips' => [
            'Critical notifications (system failures) cannot be fully disabled',
            'Email notifications are useful for approvals that need quick attention',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Notifications', 'route' => 'notifications.index'],
        ],
    ],

    // ── Missing detail/edit pages ─────────────────────────────────────

    'credentials.show' => [
        'title' => 'Credential Detail',
        'description' => 'View and manage a stored credential — its type, status, expiry, and linked resources.',
        'steps' => [
            'Review the credential type and status',
            'Check the expiry date if one is set',
            'Use the rotate action to update the secret without downtime',
            'Delete the credential if it is no longer needed',
        ],
        'tips' => [
            'The secret value is never shown after creation — rotate to replace it',
            'Expired credentials are flagged automatically',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Credentials', 'route' => 'credentials.index'],
            ['label' => 'Tools', 'route' => 'tools.index'],
        ],
    ],

    'crews.execute' => [
        'title' => 'Execute Crew',
        'description' => 'Run a crew with a specific goal and watch agents collaborate in real time.',
        'steps' => [
            'Enter the goal the crew should accomplish',
            'Optionally provide input context for the agents',
            'Click "Execute" to start the crew run',
            'Watch the execution log as agents work through their tasks',
        ],
        'tips' => [
            'A clear, specific goal produces better results',
            'You can run the same crew multiple times with different goals',
            'Execution cost depends on the number of agents and their LLM usage',
        ],
        'prerequisites' => [
            ['label' => 'Configure crew members first', 'route' => 'crews.index'],
        ],
        'related' => [
            ['label' => 'All Crews', 'route' => 'crews.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    'projects.edit' => [
        'title' => 'Edit Project',
        'description' => 'Update a project\'s configuration — name, workflow, schedule, budget, and input data.',
        'steps' => [
            'Update the project name and description as needed',
            'Change the assigned workflow if required',
            'Adjust the schedule for continuous projects',
            'Update budget limits and input data',
        ],
        'tips' => [
            'Changes take effect on the next run — active runs are not affected',
            'You can switch between one-shot and continuous project types',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Projects', 'route' => 'projects.index'],
            ['label' => 'Workflows', 'route' => 'workflows.index'],
        ],
    ],

    'projects.kanban' => [
        'title' => 'Project Kanban',
        'description' => 'Visualize project milestones and tasks on a kanban board for tracking progress.',
        'steps' => [
            'View milestones organized by status columns',
            'Drag milestones between columns to update their status',
            'Click a milestone to view its details',
            'Track overall project progress at a glance',
        ],
        'tips' => [
            'Milestones are created from workflow step completions',
            'Use the kanban view for a high-level progress overview',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Project Detail', 'route' => 'projects.index'],
            ['label' => 'Workflows', 'route' => 'workflows.index'],
        ],
    ],

    'workflows.edit' => [
        'title' => 'Edit Workflow',
        'description' => 'Visual editor for modifying workflow DAGs. Update nodes, connections, and agent assignments in an existing workflow.',
        'steps' => [
            'Modify existing nodes or add new ones to the graph',
            'Update agent assignments on agent nodes',
            'Reconnect edges to change the execution flow',
            'Click "Validate" to check for graph errors before saving',
        ],
        'tips' => [
            'Changes are saved when you click "Save" — they do not auto-save',
            'Active projects using this workflow will pick up changes on their next run',
            'Use "Duplicate" to create a variant without modifying the original',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Workflows', 'route' => 'workflows.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Projects', 'route' => 'projects.index'],
        ],
    ],

    'workflows.schedule' => [
        'title' => 'Schedule Workflow',
        'description' => 'Create a continuous project from this workflow with an automated schedule.',
        'steps' => [
            'Set the project name and description',
            'Choose a schedule frequency (hourly, daily, weekly, etc.)',
            'Configure the overlap policy for concurrent runs',
            'Set a budget limit for automatic cost control',
        ],
        'tips' => [
            'This creates a continuous project that runs the workflow on schedule',
            'The overlap policy controls what happens when a new run overlaps with an existing one',
            'You can pause and resume the schedule from the project detail page',
        ],
        'prerequisites' => [
            ['label' => 'Validate the workflow first', 'route' => 'workflows.index'],
        ],
        'related' => [
            ['label' => 'All Workflows', 'route' => 'workflows.index'],
            ['label' => 'Projects', 'route' => 'projects.index'],
        ],
    ],

    'app.marketplace.publish' => [
        'title' => 'Publish to Marketplace',
        'description' => 'Share a skill, agent, or workflow with the community by publishing it to the marketplace.',
        'steps' => [
            'Select the item to publish (skill, agent, or workflow)',
            'Write a clear title and description',
            'Choose a category and add tags',
            'Set the visibility (public or unlisted) and submit',
        ],
        'tips' => [
            'A good description and clear tags help others discover your listing',
            'Published items are copies — changes to the original do not propagate',
            'You can update or unpublish your listings at any time',
        ],
        'prerequisites' => [
            'Create a skill, agent, or workflow to publish',
        ],
        'related' => [
            ['label' => 'Marketplace', 'route' => 'app.marketplace.index'],
            ['label' => 'Skills', 'route' => 'skills.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    'app.marketplace.show' => [
        'title' => 'Marketplace Listing',
        'description' => 'View the details of a marketplace listing — description, reviews, install count, and configuration.',
        'steps' => [
            'Read the description and documentation',
            'Check reviews and install count for community feedback',
            'Click "Install" to add this to your team',
            'Leave a review after trying it out',
        ],
        'tips' => [
            'Installed items are copies — you can customize them after installing',
            'Check the version and last updated date for freshness',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Marketplace', 'route' => 'app.marketplace.index'],
        ],
    ],

    'contacts.show' => [
        'title' => 'Contact Detail',
        'description' => 'View a contact\'s unified identity across all communication channels and their signal history.',
        'steps' => [
            'Review the contact\'s linked channel identities',
            'Browse their signal history across all channels',
            'Merge with another contact if they represent the same person',
            'Unlink channels that were incorrectly associated',
        ],
        'tips' => [
            'Contacts can have identities across Telegram, email, WhatsApp, and more',
            'Merging is irreversible — verify before combining contacts',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'All Contacts', 'route' => 'contacts.index'],
            ['label' => 'Signal Connectors', 'route' => 'signals.connectors'],
        ],
    ],

    'settings' => [
        'title' => 'Platform Settings',
        'description' => 'Global platform configuration for super administrators. Manage system-wide defaults, provider settings, and feature flags.',
        'steps' => [
            'Review and update platform-wide AI provider defaults',
            'Configure system limits and defaults',
            'Manage feature flags and global settings',
            'Check platform-level health indicators',
        ],
        'tips' => [
            'Changes here affect all teams on the platform',
            'Team-level settings override platform defaults where applicable',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Health', 'route' => 'health'],
            ['label' => 'Audit Log', 'route' => 'audit'],
        ],
    ],

    // ── Changelog ────────────────────────────────────────────────────

    'changelog' => [
        'title' => "What's New",
        'description' => 'See the latest platform changes, new features, improvements, and bug fixes.',
        'steps' => [
            'Browse the timeline to see recent changes',
            'Click on a version to expand its full changelog',
            'New entries are highlighted when you visit after an update',
        ],
        'tips' => [
            'A dot indicator appears in the sidebar when new changes are available',
            'The badge clears automatically when you visit this page',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Health', 'route' => 'health'],
            ['label' => 'Settings', 'route' => 'settings'],
        ],
    ],

    // ── Team Graph ───────────────────────────────────────────────────

    'team-graph' => [
        'title' => 'Team Graph',
        'description' => 'Live visual map of your workspace. Each node is an Agent, Human, or Crew; edges show membership and collaboration. The activity firehose on the right shows what is happening across the team in real time.',
        'steps' => [
            'Read the legend top-left: blue circle = Agent, green ellipse = Human, orange hexagon = Crew',
            'Edges labeled "worker" mean an agent is a member of a crew',
            'Click any node to see its recent activity in the side drawer',
            'Watch the right panel for live experiment transitions and agent executions',
        ],
        'tips' => [
            'Status indicator shows "live" when WebSockets are connected, "polling 5s" when falling back',
            'Pulsing yellow border on a node means it is active right now',
            'New nodes appear on full page reload — drag the canvas to reposition',
            'Use this view to spot orphan agents (no crew) or overloaded hubs',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Crews', 'route' => 'crews.index'],
            ['label' => 'Audit Log', 'route' => 'audit'],
        ],
    ],

    // ── Evaluation pipeline ─────────────────────────────────────────

    'evaluation.index' => [
        'title' => 'Eval Pipeline',
        'description' => 'Curate test cases from real agent runs, replay them against alternative configurations, and compare outputs side-by-side. The cycle: real run → curate → replay → diff → promote.',
        'steps' => [
            'Pick a real agent run that produced a notable output',
            'Curate the input/expected pair into a test case',
            'Replay the case against a different agent, model, or skill version',
            'Compare outputs in the Compare Runs view to decide which configuration wins',
        ],
        'tips' => [
            'Eval cases are scoped to a team — they capture institutional knowledge over time',
            'LLM-as-judge scoring is available when no exact match is required',
            'Promote winning configurations directly to live agents from the compare view',
        ],
        'prerequisites' => [
            ['label' => 'At least one Agent has runs to curate from', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Skills', 'route' => 'skills.index'],
        ],
    ],

    'evaluation.compare' => [
        'title' => 'Compare Runs',
        'description' => 'Side-by-side comparison of two replays of the same eval case. Use this to verify a config change actually improves output before promoting it.',
        'steps' => [
            'Pick the baseline run on the left',
            'Pick the candidate run on the right',
            'Diff the outputs, token usage, latency, and cost columns',
            'Promote the winner to the source agent or skill',
        ],
        'tips' => [
            'Differences are highlighted in green/red',
            'A failed assertion is marked with a red x even if the output looks correct — check the assertion list',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Eval Pipeline', 'route' => 'evaluation.index'],
        ],
    ],

    // ── Frameworks (Founder Mode) ───────────────────────────────────

    'frameworks.index' => [
        'title' => 'Frameworks',
        'description' => 'Browse 20+ business and product frameworks (RICE, SPIN, BANT, MEDDIC, OKRs, Lean Startup, Shape Up, Unit Economics, etc.) packaged as skills your agents can apply.',
        'steps' => [
            'Filter by category: Strategy, Product, Growth, Finance, Ops, Testing',
            'Click a framework to see its description, inputs, and example outputs',
            'Attach a framework skill to an agent to get framework-aware reasoning',
        ],
        'tips' => [
            'Founder Mode pack installs all 20 frameworks at once via Onboarding',
            'Frameworks are versioned skills — you can fork and customize the prompts',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Skills', 'route' => 'skills.index'],
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Marketplace', 'route' => 'app.marketplace.index'],
        ],
    ],

    // ── Bug reports ─────────────────────────────────────────────────

    'bug-reports.index' => [
        'title' => 'Bug Reports',
        'description' => 'Bug reports collected from your public JS widget. Reporters can comment back through the same widget — agents can be assigned to investigate or fix.',
        'steps' => [
            'Filter by project, severity, status, or reporter',
            'Click a report to see the screenshot, action/console/network logs, and breadcrumbs',
            'Delegate to an agent to auto-create an Experiment scoped to the bug',
            'Reply to the reporter through the threaded comment view',
        ],
        'tips' => [
            'Source maps are resolved automatically when registered via /api/v1/source-maps',
            'Suspect-files analysis ranks files by likelihood (0.60–0.95 confidence)',
            'Project-level structured intake forces specific fields when enabled',
        ],
        'prerequisites' => [
            ['label' => 'Configure team widget public key', 'route' => 'team.settings'],
        ],
        'related' => [
            ['label' => 'Signals', 'route' => 'signals.entities'],
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    'bug-reports.show' => [
        'title' => 'Bug Report Detail',
        'description' => 'Full bug report context: screenshot, attachments, action/console/network logs, breadcrumbs, suspect files, threaded comments. Delegate to an agent to fix it.',
        'steps' => [
            'Review the screenshot and reporter description',
            'Expand the logs sections for runtime context',
            'Check the suspect files panel for AI-ranked file likelihood',
            'Reply to the reporter or delegate to an agent',
        ],
        'tips' => [
            'Comments marked "support" are visible to the reporter; "human" comments are internal-only',
            'Resolution can be confirmed or rejected by the reporter',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Bug Reports', 'route' => 'bug-reports.index'],
        ],
    ],

    // ── Insights ────────────────────────────────────────────────────

    'insights' => [
        'title' => 'Insights',
        'description' => 'Aggregated AI-generated insights from your team\'s recent activity — top patterns, anomalies, recommended actions. Refreshed daily.',
        'steps' => [
            'Read the daily digest at the top',
            'Drill into a specific insight to see the underlying signals',
            'Mark an insight as actioned when you take a step',
        ],
        'tips' => [
            'Insights are derived from the world model + signal stream — make sure both are populated',
            'Cost is one Haiku call per refresh per team',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Dashboard', 'route' => 'dashboard'],
            ['label' => 'Audit Log', 'route' => 'audit'],
        ],
    ],

    // ── Knowledge Graph ─────────────────────────────────────────────

    'knowledge-graph.index' => [
        'title' => 'Knowledge Graph',
        'description' => 'Entity-relationship facts about your workspace: who, what, when, where, how. Vector-embedded for semantic retrieval. Used as context for every agent run.',
        'steps' => [
            'Search for an entity (person, company, project, deal, etc.)',
            'Browse facts attached to that entity',
            'Add a fact manually or let agents discover them automatically',
        ],
        'tips' => [
            'Facts are deduplicated by an LLM normalizer to keep the graph clean',
            'Each fact has a temporal validity window — historical facts stay queryable',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Memory', 'route' => 'memory.index'],
            ['label' => 'Contacts', 'route' => 'contacts.index'],
        ],
    ],

    // ── Chatbots ────────────────────────────────────────────────────

    'chatbots.index' => [
        'title' => 'Chatbots',
        'description' => 'Embeddable chat widgets backed by your agents and knowledge base. Each chatbot has its own knowledge sources, conversation log, and analytics.',
        'steps' => [
            'Create a chatbot and pick its agent',
            'Add knowledge sources (URLs, documents, FAQs)',
            'Embed the widget snippet in your website',
            'Monitor conversations and analytics from this page',
        ],
        'tips' => [
            'A chatbot can have its own LLM provider and tool set',
            'Slack, Telegram, and Tickets all integrate as chatbot channels',
        ],
        'prerequisites' => [
            ['label' => 'At least one Agent', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
            ['label' => 'Knowledge Sources', 'route' => 'knowledge.index'],
        ],
    ],

    // ── Evolution ───────────────────────────────────────────────────

    'evolution.index' => [
        'title' => 'Agent Evolution',
        'description' => 'AI-driven self-improvement. The platform analyzes each agent\'s recent execution history and proposes config changes — personality, prompt, model, tool selection. You approve with one click.',
        'steps' => [
            'Browse pending evolution proposals',
            'Click a proposal to see the diff between current and proposed config',
            'Apply or reject — applied changes create a new agent version',
        ],
        'tips' => [
            'Proposals are scored on confidence — high-confidence ones surface first',
            'Rolling back is a single click on the agent\'s config history',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    // ── Git Repositories ────────────────────────────────────────────

    'git-repositories.index' => [
        'title' => 'Git Repositories',
        'description' => 'Connected GitHub or GitLab repositories. Agents can read code, open PRs, and dispatch workflows — all routed through the per-tier risk policy gate.',
        'steps' => [
            'Click "Connect" to add a new repo (OAuth or token)',
            'Pick a mode: API-only (cloud), Sandbox (ephemeral worktree), or Bridge (local relay)',
            'Index the repo so agents can search code and call chains',
            'Attach the repo to an agent so it can act on the codebase',
        ],
        'tips' => [
            'Risky operations (commits, pushes, PR merges) route through Action Proposals when policy = "ask"',
            'API-only mode is the safest — no local clone, all operations via the provider API',
        ],
        'prerequisites' => [
            ['label' => 'Provider credentials', 'route' => 'credentials.index'],
        ],
        'related' => [
            ['label' => 'Approvals', 'route' => 'approvals.index'],
            ['label' => 'Tools', 'route' => 'tools.index'],
        ],
    ],

    'git-repositories.show' => [
        'title' => 'Repository Detail',
        'description' => 'Inspect a connected repo — file tree, recent commits, open pull requests, indexed code structure, attached agents.',
        'steps' => [
            'Browse the file tree to confirm indexing succeeded',
            'Open the Code Structure tab to see classes, functions, and call chains',
            'Watch Pull Requests for agent-opened PRs awaiting review',
        ],
        'tips' => [
            'Re-index after major branch changes',
            'The repo connects to the same per-tier risk policy used for integrations',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Git Repositories', 'route' => 'git-repositories.index'],
        ],
    ],

    // ── External Agents (agentverse) ────────────────────────────────

    'external-agents.index' => [
        'title' => 'External Agents',
        'description' => 'Agents from other teams or platforms reachable via the agent-chat protocol. Browse, attach, or chat directly.',
        'steps' => [
            'Browse the agentverse for public agents',
            'Click an agent to see its capabilities and pricing',
            'Attach to a project or chat directly from the detail view',
        ],
        'tips' => [
            'External agents are billed separately by their host platform',
            'Manifest is fetched live — capabilities reflect the upstream owner\'s changes',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    // ── Email Templates ─────────────────────────────────────────────

    'email.templates.index' => [
        'title' => 'Email Templates',
        'description' => 'Reusable email templates for outbound and notifications. Generate new templates from a prompt with AI, or hand-craft with the visual editor.',
        'steps' => [
            'Click "New Template" to start from blank or AI prompt',
            'Pick a theme (controls colors, fonts, header/footer)',
            'Edit blocks in the visual builder',
            'Preview across light and dark, mobile and desktop',
        ],
        'tips' => [
            'AI generation produces both subject and body — refine iteratively',
            'Templates are versioned — earlier versions stay sendable',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Email Themes', 'route' => 'email.themes.index'],
            ['label' => 'Outbound', 'route' => 'signals.bindings'],
        ],
    ],

    // ── Tools Marketplace (Smithery + popular) ──────────────────────

    'tools.marketplace' => [
        'title' => 'MCP Marketplace',
        'description' => 'Browse 300+ MCP servers from the Smithery registry plus the curated FleetQ popular-tools catalog. One-click install attaches the tool to your team.',
        'steps' => [
            'Search by name or browse by category',
            'Click a tool to see its required env vars and capabilities',
            'Install — credentials prompt appears if needed',
            'Attach the installed tool to specific agents',
        ],
        'tips' => [
            'Smithery tools update via the upstream registry — check for new versions periodically',
            'You can also self-author tools via the Plugin SDK',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Tools', 'route' => 'tools.index'],
            ['label' => 'Credentials', 'route' => 'credentials.index'],
        ],
    ],

    'tools.templates' => [
        'title' => 'GPU Tool Templates',
        'description' => 'One-click GPU compute templates: GLM-OCR, Whisper, SDXL Turbo, FLUX.1, BGE-M3, XTTS v2, Qwen2.5 Coder, Mistral 7B, Wan2.1, Florence-2, SAM 2, Kokoro TTS, NLLB-200, MusicGen, F5-TTS, Table Transformer.',
        'steps' => [
            'Browse templates filtered by use case (OCR, image, audio, vision, code)',
            'Click "Deploy" — the tool is created in your team with the right transport config',
            'Pick a compute provider (RunPod, Replicate, Fal.ai, Vast.ai)',
            'Attach to an agent or skill so it can invoke the GPU endpoint',
        ],
        'tips' => [
            'Each template includes recommended hardware tier and cold-start expectations',
            'Templates are skill-shaped — you can fork to customize prompts/inputs',
        ],
        'prerequisites' => [
            ['label' => 'A GPU provider credential', 'route' => 'credentials.index'],
        ],
        'related' => [
            ['label' => 'Tools', 'route' => 'tools.index'],
            ['label' => 'Skills', 'route' => 'skills.index'],
        ],
    ],

    'tools.federation-groups' => [
        'title' => 'Tool Federation Groups',
        'description' => 'Group multiple MCP tools into a single virtual "super-tool" that an agent can call. Useful for grouping by domain (e.g., all GitHub tools as one federated tool).',
        'steps' => [
            'Create a group and pick its constituent tools',
            'Define a routing rule (round-robin, primary+failover, parallel-fanout)',
            'Attach the group to an agent like any other tool',
        ],
        'tips' => [
            'Federation reduces an agent\'s tool surface area — fewer tools = better LLM tool selection',
            'Failover routing lets you keep working when one MCP server goes down',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Tools', 'route' => 'tools.index'],
        ],
    ],

    'tools.search-history' => [
        'title' => 'Tool Search History',
        'description' => 'Audit log of every MCP tool invocation across the team. Filter by tool, agent, success/failure, or time window.',
        'steps' => [
            'Filter by tool name or agent to narrow the firehose',
            'Click a row to see the input/output payloads (encrypted secrets are redacted)',
            'Use the time-window selector to scope to a specific incident',
        ],
        'tips' => [
            'Failed invocations are highlighted red — use this to spot flaky external services',
            'Outputs are retained per the team\'s audit retention plan',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Tools', 'route' => 'tools.index'],
            ['label' => 'Audit Log', 'route' => 'audit'],
        ],
    ],

    // ── World Model ─────────────────────────────────────────────────

    'world-model.index' => [
        'title' => 'World Model',
        'description' => 'What the platform "knows" about your workspace — entities, relationships, knowledge-graph facts, top contacts, recent intent signals. Used as context for every agent run.',
        'steps' => [
            'Browse entity counts and recent activity across domains',
            'Drill into a specific entity (contact, project, signal) to see its facts',
            'Use the search bar to find an entity by name across all domains',
        ],
        'tips' => [
            'World-model context is automatically injected into agent prompts — keep it clean for better outputs',
            'Stale or wrong facts: delete from the entity detail page; agents stop using them on the next run',
            'The daily digest (see Insights) summarizes world-model deltas',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Knowledge Graph', 'route' => 'knowledge-graph.index'],
            ['label' => 'Memory', 'route' => 'memory.index'],
            ['label' => 'Insights', 'route' => 'insights'],
        ],
    ],

    // ── Signals ─────────────────────────────────────────────────────

    'signals.index' => [
        'title' => 'Signals',
        'description' => 'Inbound events from external sources — webhooks, RSS, email, GitHub, Sentry, calendars, chat platforms. Each signal can trigger experiments via Triggers.',
        'steps' => [
            'Filter by connector, severity, status, or time window',
            'Click a signal to see its full payload, processing history, and downstream actions',
            'Mark as triaged / dismissed / escalated',
        ],
        'tips' => [
            'Signals get an AI-classified `intent` tag (Question / Bug / FeatureRequest / Spam / Other)',
            'Use Trigger Rules to auto-launch experiments on matching signals — no manual review needed',
            'Bug reports are signals with extra structure — see the Bug Reports page',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Connector Bindings', 'route' => 'signals.bindings'],
            ['label' => 'Triggers', 'route' => 'triggers.index'],
            ['label' => 'Contacts', 'route' => 'contacts.index'],
        ],
    ],

    // ── AI Routing Metrics ──────────────────────────────────────────

    'metrics.ai-routing' => [
        'title' => 'AI Routing Metrics',
        'description' => 'How requests are routed across providers and models — fallbacks triggered, circuit breaker trips, semantic cache hits, BYOK vs platform usage. Tune for cost and reliability.',
        'steps' => [
            'Review provider usage breakdown (Anthropic / OpenAI / Google / local)',
            'Check fallback chain trigger frequency',
            'Inspect semantic cache hit rate — higher = lower spend',
            'Drill into a specific provider to see latency and error rates',
        ],
        'tips' => [
            'A provider with >5% error rate is auto-circuit-broken for 60s',
            'Semantic cache reuses results across teams — privacy-safe via vector similarity',
            'Local agents (Codex, Claude Code) show as zero-cost in the cost panel',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Model Comparison', 'route' => 'metrics.models'],
            ['label' => 'Health', 'route' => 'health'],
            ['label' => 'Audit Log', 'route' => 'audit'],
        ],
    ],

    // ── Websites (Website Builder) ──────────────────────────────────

    'websites.index' => [
        'title' => 'Websites',
        'description' => 'Visual website builder with block-based editor, dynamic widgets (recent posts, page lists), AI generation, and one-click deployment to Vercel or downloadable ZIP.',
        'steps' => [
            'Click "New Website" to start blank or generate from prompt',
            'Pick a template or describe your site for AI generation',
            'Edit pages in the visual builder',
            'Publish — choose Vercel deployment or ZIP download',
        ],
        'tips' => [
            'Plugins extend the builder: blog (posts), forms (contact), chatbot (widget), e-commerce',
            'Dynamic widgets like `<!-- fleetq:recent-posts -->` are rendered server-side at request time',
            'Use Unpublish to take a website offline without deleting it',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Email Templates', 'route' => 'email.templates.index'],
            ['label' => 'Marketplace', 'route' => 'app.marketplace.index'],
        ],
    ],

    'websites.create' => [
        'title' => 'Create Website',
        'description' => 'Start a new website — from a blank canvas, a template, or AI generation from a natural-language prompt.',
        'steps' => [
            'Pick the start mode: Blank / Template / AI Generate',
            'Describe your site in plain English (for AI mode)',
            'Pick which plugins to enable (blog, forms, chatbot, e-commerce)',
            'Continue to the builder',
        ],
        'tips' => [
            'AI generation uses Claude — expect 30–60 seconds for a multi-page site',
            'You can change plugins later from the website detail page',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Websites', 'route' => 'websites.index'],
        ],
    ],

    'websites.show' => [
        'title' => 'Website Detail',
        'description' => 'Inspect a website — pages, plugins, deployment status, dynamic content widgets, navigation tree.',
        'steps' => [
            'Browse pages and click any to open in the builder',
            'Toggle plugins on/off (changes apply immediately)',
            'Trigger a deployment from the deploy panel',
            'Use the navigation editor to reorder pages or rename links',
        ],
        'tips' => [
            'Internal-link rewriting runs on every navigation change — broken `<a href="...">` links are auto-repaired',
            'Page version history is kept; you can rollback any page',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Websites', 'route' => 'websites.index'],
        ],
    ],

    'websites.pages.edit' => [
        'title' => 'Page Builder',
        'description' => 'Block-based visual editor for a single page. Drag blocks, edit content, preview live.',
        'steps' => [
            'Drag blocks from the sidebar palette onto the canvas',
            'Click any block to edit its content / style / dynamic data binding',
            'Use Preview to see the rendered output without saving',
            'Click Save — the page is versioned and the live site updates',
        ],
        'tips' => [
            'Press ⌘Z / Ctrl+Z to undo — block-level history is per-session',
            'AI helpers can rewrite a block\'s copy from a prompt',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Website Detail', 'route' => 'websites.index'],
        ],
    ],

    'websites.pages.preview' => [
        'title' => 'Page Preview',
        'description' => 'Sandboxed preview of a page as visitors will see it, before publishing.',
        'steps' => [
            'Compare the preview to the editor view',
            'Test responsive: resize the window or use the device toggle',
            'Click "Publish" when satisfied, or "Back to editor" to keep iterating',
        ],
        'tips' => [
            'Dynamic widgets resolve in preview just like production — you see real recent posts, real forms, etc.',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Page Builder', 'route' => 'websites.index'],
        ],
    ],

    // ── Plugins ─────────────────────────────────────────────────────

    'plugins' => [
        'title' => 'Plugins',
        'description' => 'Extend FleetQ with composer-installable plugins — additional MCP tools, signal connectors, UI panels, website blocks. On cloud, plugins are platform-curated and toggleable per-team.',
        'steps' => [
            'Browse available plugins',
            'Toggle a plugin on/off for your team',
            'Configure required env vars or credentials',
            'New tools / connectors / blocks become available immediately',
        ],
        'tips' => [
            'Self-hosted: plugins are standard Composer packages (`composer require ...`)',
            'Cloud: only platform-vetted plugins are exposed (security)',
            'Disabled plugins keep their data — you can re-enable later',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Marketplace', 'route' => 'app.marketplace.index'],
            ['label' => 'Tools', 'route' => 'tools.index'],
        ],
    ],

    // ── Knowledge Sources ───────────────────────────────────────────

    'knowledge.index' => [
        'title' => 'Knowledge Sources',
        'description' => 'Documents, URLs, FAQs, and structured data that feed your agents and chatbots. Each source is chunked, embedded, and indexed for semantic retrieval.',
        'steps' => [
            'Add a source: upload PDF/DOCX, paste URL, or write FAQ pairs',
            'Wait for chunking + embedding (queued job, usually <60s)',
            'Attach the source to an agent or chatbot',
            'Re-index when the source content changes',
        ],
        'tips' => [
            'URL sources can be set to auto-recrawl on a schedule',
            'Chunk size is tuned per source type — adjust manually if retrieval is poor',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Memory', 'route' => 'memory.index'],
            ['label' => 'Chatbots', 'route' => 'chatbots.index'],
        ],
    ],

    // ── Chatbots — sub-pages ────────────────────────────────────────

    'chatbots.create' => [
        'title' => 'Create Chatbot',
        'description' => 'Spin up a new chatbot backed by an agent. Pick the channel (web widget, Slack, Telegram, ticket inbox), pick an agent, attach knowledge sources.',
        'steps' => [
            'Name your chatbot and pick its primary channel',
            'Pick the agent that drives conversations',
            'Attach knowledge sources for retrieval-augmented answers',
            'Continue — the embed snippet / channel-specific config appears',
        ],
        'tips' => [
            'You can switch the agent later without breaking conversations',
            'Per-chatbot LLM provider override is available',
        ],
        'prerequisites' => [
            ['label' => 'At least one Agent', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'Chatbots', 'route' => 'chatbots.index'],
        ],
    ],

    'chatbots.show' => [
        'title' => 'Chatbot Detail',
        'description' => 'Configure a chatbot — agent, knowledge sources, embed snippet, channel-specific settings, conversation history.',
        'steps' => [
            'Copy the embed snippet for web widgets',
            'Adjust per-channel settings (Slack OAuth, Telegram bot token, etc.)',
            'Tune the system prompt or pick a different agent',
            'Browse the Conversations tab to see live sessions',
        ],
        'tips' => [
            'Knowledge source changes propagate on the next conversation turn — no rebuild needed',
            'Use Analytics to spot common drop-off points',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Chatbots', 'route' => 'chatbots.index'],
            ['label' => 'Knowledge Sources', 'route' => 'knowledge.index'],
        ],
    ],

    'chatbots.analytics' => [
        'title' => 'Chatbot Analytics',
        'description' => 'Conversation volume, deflection rate, top intents, fallback rate, latency percentiles for one chatbot.',
        'steps' => [
            'Pick a time window',
            'Review volume + deflection rate (chats resolved without human)',
            'Drill into top intents to spot common questions',
            'Check fallback transcripts to identify gaps in knowledge sources',
        ],
        'tips' => [
            'High fallback rate = add knowledge sources or tune the system prompt',
            'p95 latency includes LLM time + knowledge retrieval',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Chatbot Detail', 'route' => 'chatbots.index'],
        ],
    ],

    'chatbots.conversations' => [
        'title' => 'Chatbot Conversations',
        'description' => 'All conversations for one chatbot — search, filter by channel/sentiment/resolution, replay turn-by-turn, export.',
        'steps' => [
            'Filter by channel, status, or date range',
            'Click a conversation to replay turn-by-turn',
            'Export selected conversations as JSON',
        ],
        'tips' => [
            'Conversations are retained per the team\'s audit retention plan',
            'Replay shows tool calls and retrieved context — useful for debugging',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Chatbot Detail', 'route' => 'chatbots.index'],
        ],
    ],

    'chatbots.knowledge' => [
        'title' => 'Chatbot Knowledge',
        'description' => 'Knowledge sources scoped to one chatbot. Override or extend the team-wide knowledge base for this specific bot.',
        'steps' => [
            'Add chatbot-specific sources (FAQs, URLs, docs)',
            'Toggle inherited team-wide sources on/off for this bot',
            'Re-index after changes to refresh embeddings',
        ],
        'tips' => [
            'Bot-specific overrides take precedence over team-wide sources at retrieval time',
            'Use this to give different bots different "voices" or knowledge depth',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Chatbot Detail', 'route' => 'chatbots.index'],
            ['label' => 'Knowledge Sources', 'route' => 'knowledge.index'],
        ],
    ],

    // ── Email — sub-pages ───────────────────────────────────────────

    'email.themes.index' => [
        'title' => 'Email Themes',
        'description' => 'Design themes (colors, fonts, header/footer) reused across email templates. Edit once, applied everywhere.',
        'steps' => [
            'Click "New Theme" or duplicate an existing one',
            'Pick brand colors, typography, header logo, footer text',
            'Save — templates using this theme update on next send',
        ],
        'tips' => [
            'Test each theme in light + dark mode previews',
            'Themes can be duplicated to A/B test brand variations',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Email Templates', 'route' => 'email.templates.index'],
        ],
    ],

    'email.themes.show' => [
        'title' => 'Email Theme Detail',
        'description' => 'Edit a single theme — colors, typography, header/footer, dark-mode overrides.',
        'steps' => [
            'Adjust colors via the picker; preview updates live',
            'Override individual fields for dark mode',
            'Save — changes propagate to all templates using this theme',
        ],
        'tips' => [
            'Hex codes accept short form (#fff) or rgb()',
            'A theme used by published templates can\'t be deleted — duplicate first',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Email Themes', 'route' => 'email.themes.index'],
        ],
    ],

    'email.templates.edit' => [
        'title' => 'Edit Email Template',
        'description' => 'Visual block editor for an email template — content blocks, dynamic variables, theme application.',
        'steps' => [
            'Drag content blocks from the sidebar onto the email',
            'Click any block to edit text or insert dynamic variables',
            'Switch the theme from the top bar',
            'Preview across light/dark + mobile/desktop',
        ],
        'tips' => [
            'Variables like `{{user.name}}` are resolved at send time',
            'Templates are versioned — earlier versions stay sendable',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Email Templates', 'route' => 'email.templates.index'],
            ['label' => 'Email Themes', 'route' => 'email.themes.index'],
        ],
    ],

    'email.templates.preview' => [
        'title' => 'Email Template Preview',
        'description' => 'See an email exactly as a recipient will. Switch between light/dark mode and viewport sizes.',
        'steps' => [
            'Toggle light vs dark mode',
            'Switch between mobile and desktop preview',
            'Send a test email to yourself',
        ],
        'tips' => [
            'Test with real data by picking a sample recipient',
            'Outlook desktop client can render differently — use a 3rd-party tester for critical campaigns',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Email Templates', 'route' => 'email.templates.index'],
        ],
    ],

    // ── Workflow Evaluations (different from Eval Pipeline) ────────

    'evaluations.index' => [
        'title' => 'Workflow Evaluations',
        'description' => 'Test runs of your workflows against curated inputs. Like the Eval Pipeline but at the workflow level — full DAG execution with assertion checks per step.',
        'steps' => [
            'Pick a workflow you want to test',
            'Curate input cases (manually or auto-derived from real runs)',
            'Trigger the evaluation — each case runs the full workflow',
            'Review pass/fail per case + per assertion',
        ],
        'tips' => [
            'Workflow evaluations are slower than skill evaluations — they execute the full DAG',
            'Failed assertions surface the exact step that diverged',
        ],
        'prerequisites' => [
            ['label' => 'A Workflow with at least one run', 'route' => 'workflows.index'],
        ],
        'related' => [
            ['label' => 'Workflows', 'route' => 'workflows.index'],
            ['label' => 'Eval Pipeline (skill-level)', 'route' => 'evaluation.index'],
        ],
    ],

    // ── External Agents ─────────────────────────────────────────────

    'external-agents.show' => [
        'title' => 'External Agent Detail',
        'description' => 'A single external agent\'s capabilities, pricing, manifest URL, and chat history. Connect, attach to a project, or chat directly.',
        'steps' => [
            'Read the capabilities manifest (skills, tools, supported tasks)',
            'Review pricing — billed by the upstream platform, not FleetQ',
            'Attach to a project, or click "Chat" for one-off interaction',
        ],
        'tips' => [
            'Manifests are fetched live — capabilities reflect upstream changes immediately',
            'External agents follow the agent-chat protocol; you can self-host one with the SDK',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'External Agents', 'route' => 'external-agents.index'],
            ['label' => 'Agentverse', 'route' => 'external-agents.agentverse'],
        ],
    ],

    'external-agents.agentverse' => [
        'title' => 'Agentverse',
        'description' => 'Public directory of external agents implementing the agent-chat protocol — discover capabilities you don\'t want to build yourself.',
        'steps' => [
            'Browse by category or search by capability',
            'Click an agent to see its detail page',
            'Connect agents you want to use',
        ],
        'tips' => [
            'Agentverse is a federated directory — anyone can publish a manifest',
            'Verified agents have a checkmark badge; unverified ones run at your own risk',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'External Agents', 'route' => 'external-agents.index'],
        ],
    ],

    // ── Git Repository — Create ─────────────────────────────────────

    'git-repositories.create' => [
        'title' => 'Connect Git Repository',
        'description' => 'Connect a GitHub or GitLab repository so agents can read code, open PRs, and dispatch workflows. Pick a mode based on your security and capability needs.',
        'steps' => [
            'Pick the provider (GitHub / GitLab) and authenticate via OAuth',
            'Select the repository and branch',
            'Pick a mode: API-only (safest), Sandbox (ephemeral worktree), or Bridge (local relay)',
            'Index the repo so agents can search code',
        ],
        'tips' => [
            'API-only mode never clones — all operations via the provider API. Best for production',
            'Sandbox mode gives agents a real working directory but resets it after each run',
            'Bridge mode requires the relay binary — most powerful but needs operator setup',
        ],
        'prerequisites' => [
            ['label' => 'Provider OAuth credential', 'route' => 'credentials.index'],
        ],
        'related' => [
            ['label' => 'Git Repositories', 'route' => 'git-repositories.index'],
        ],
    ],

    // ── Integrations — sub-pages ────────────────────────────────────

    'integrations.show' => [
        'title' => 'Integration Detail',
        'description' => 'Inspect a connected integration — health check, identity (which account), recent actions, capabilities, audit trail. Edit or disconnect from here.',
        'steps' => [
            'Review the identity card to confirm which account is connected',
            'Run a ping test to verify the credential is still valid',
            'Browse the recent-actions log for audit trail',
            'Edit or disconnect using the action panel',
        ],
        'tips' => [
            'Identity is auto-cached on each ping — if it changes, the card flags it',
            'Mutating actions (create / update / delete) route through the per-tier risk policy',
            'Use Disconnect to invalidate the credential platform-side immediately',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Integrations', 'route' => 'integrations.index'],
            ['label' => 'Approvals', 'route' => 'approvals.index'],
        ],
    ],

    'integrations.edit' => [
        'title' => 'Edit Integration',
        'description' => 'Update integration credentials, scopes, or display name. Schema-driven form per provider.',
        'steps' => [
            'Update the fields you want to change',
            'Leave secret fields blank to keep the existing value',
            'Save — a re-ping runs automatically to verify',
        ],
        'tips' => [
            'Empty secret fields preserve the stored value (don\'t clear by accident)',
            'Scope changes may require re-authorization — the form will redirect you to OAuth if needed',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Integration Detail', 'route' => 'integrations.index'],
        ],
    ],

    // ── Marketplace alt routes ──────────────────────────────────────

    'marketplace.index' => [
        'title' => 'Marketplace',
        'description' => 'Community-shared agents, skills, workflows, and frameworks. Install with one click, fork to customize, publish your own.',
        'steps' => [
            'Browse by category or search',
            'Click a listing to see its details, reviews, risk score',
            'Install — config is copied into your team',
        ],
        'tips' => [
            'Every listing gets an AI risk score before publish — flagged ones surface a warning',
            'Installed items are forks — you can edit them without affecting the original',
            'Publish your own to earn marketplace karma',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Marketplace (in-app)', 'route' => 'app.marketplace.index'],
            ['label' => 'Skills', 'route' => 'skills.index'],
        ],
    ],

    'marketplace.show' => [
        'title' => 'Marketplace Listing',
        'description' => 'Detailed view of a marketplace listing — author, risk score, reviews, version history, install button.',
        'steps' => [
            'Read the listing description and recent reviews',
            'Check the risk score (low / medium / high)',
            'Click Install — it forks into your team workspace',
            'Leave a review after using it',
        ],
        'tips' => [
            'High-risk listings have an explicit warning panel — read it before installing',
            'Version history shows what changed between releases',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Marketplace', 'route' => 'app.marketplace.index'],
        ],
    ],

    'marketplace.category' => [
        'title' => 'Marketplace Category',
        'description' => 'Listings filtered by category (e.g., Research, Customer Support, Code Review).',
        'steps' => [
            'Browse listings within this category',
            'Sort by popularity, recent, or rating',
            'Click any listing for the detail view',
        ],
        'tips' => [
            'Categories are taxonomy-driven — listings can appear in multiple',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Marketplace', 'route' => 'app.marketplace.index'],
        ],
    ],

    // ── Triggers — Create ───────────────────────────────────────────

    'triggers.create' => [
        'title' => 'Create Trigger Rule',
        'description' => 'Auto-launch experiments or projects when inbound signals match conditions. Map signal fields to project inputs, set cooldowns and concurrency caps.',
        'steps' => [
            'Pick the source: which connector should drive this trigger',
            'Define conditions (equals, contains, threshold) on signal fields',
            'Pick the target: experiment template or project',
            'Map signal fields to target inputs',
            'Set cooldown and concurrency limits',
        ],
        'tips' => [
            'Use the test panel to dry-run against historical signals before activating',
            'Cooldown prevents flap-loops; concurrency cap limits total parallel runs',
        ],
        'prerequisites' => [
            ['label' => 'A configured connector binding', 'route' => 'signals.bindings'],
            ['label' => 'A target experiment or project', 'route' => 'experiments.index'],
        ],
        'related' => [
            ['label' => 'Triggers', 'route' => 'triggers.index'],
            ['label' => 'Signals', 'route' => 'signals.entities'],
        ],
    ],

    // ── Telegram Bots ───────────────────────────────────────────────

    'telegram.bots' => [
        'title' => 'Telegram Bots',
        'description' => 'Register and manage Telegram bots for inbound signals or chatbot channels. Each bot has its own webhook + routing mode.',
        'steps' => [
            'Create a bot in BotFather and copy its token',
            'Click "Register Bot" and paste the token',
            'Pick a routing mode (inbound signals / chatbot / both)',
            'Set the webhook (auto-configured for cloud)',
        ],
        'tips' => [
            'Polling mode is available for self-hosted installations behind firewalls',
            'Each bot can be bound to multiple chats — chatbinding is per-chat',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Connector Bindings', 'route' => 'signals.bindings'],
            ['label' => 'Chatbots', 'route' => 'chatbots.index'],
        ],
    ],

    // ── Outbound Connectors ─────────────────────────────────────────

    'outbound.email' => [
        'title' => 'Outbound Email',
        'description' => 'Configure outbound email — platform mail driver or your own SMTP. Used by experiments, chatbots, and notifications.',
        'steps' => [
            'Pick driver: platform (uses FleetQ infrastructure) or custom SMTP',
            'For SMTP: enter host, port, credentials, encryption',
            'Set the default From address and reply-to',
            'Send a test email to verify',
        ],
        'tips' => [
            'Custom SMTP is preferred for compliance / deliverability',
            'Platform mail has fair-use rate limits per plan',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Email Templates', 'route' => 'email.templates.index'],
        ],
    ],

    'outbound.notifications' => [
        'title' => 'Outbound Notifications',
        'description' => 'In-app notification config — what triggers notifications, who receives them, default delivery channels.',
        'steps' => [
            'Pick which event types fire notifications',
            'Set the default channels (in-app / email / Telegram / Slack)',
            'Adjust per-user overrides via Profile > Notifications',
        ],
        'tips' => [
            'Critical alerts (failed deploys, budget exceeded) bypass quiet hours',
            'Each user can override defaults — these are team baselines',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Notification Preferences', 'route' => 'notifications.preferences'],
            ['label' => 'Notifications', 'route' => 'notifications.index'],
        ],
    ],

    'outbound.webhooks' => [
        'title' => 'Outbound Webhooks',
        'description' => 'Configure webhook endpoints that receive event payloads from FleetQ — experiment transitions, signal ingests, audit events. Bring your own infrastructure.',
        'steps' => [
            'Create an endpoint with the target URL',
            'Pick event types to subscribe to',
            'Set the HMAC secret (used for signature verification on your end)',
            'Test with a sample payload',
        ],
        'tips' => [
            'Use webhooks to integrate with custom internal tools',
            'Failed deliveries retry with exponential backoff for up to 24h',
            'Verify signatures on your end — never trust headers alone',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Audit Log', 'route' => 'audit'],
        ],
    ],

    'outbound.whatsapp' => [
        'title' => 'Outbound WhatsApp',
        'description' => 'Send messages via WhatsApp Business API. Requires Meta business account + approved templates.',
        'steps' => [
            'Add WhatsApp credentials (phone number ID + access token)',
            'Verify the webhook (we provide the URL + verify token)',
            'Pick a Meta-approved message template',
            'Send a test message',
        ],
        'tips' => [
            'WhatsApp templates require Meta approval — apply in Meta Business Manager first',
            'Free-form messages only work within a 24h customer-initiated session',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Outbound Notifications', 'route' => 'outbound.notifications'],
        ],
    ],

    // ── Voice Sessions ──────────────────────────────────────────────

    'agents.voice' => [
        'title' => 'Voice Sessions',
        'description' => 'Real-time voice conversations with your agents over LiveKit. Each session has audio recording, live transcript, and post-call summarization.',
        'steps' => [
            'Pick an agent — voice-enabled agents have a microphone badge',
            'Allow microphone access when prompted',
            'Talk naturally — the agent responds in voice',
            'Review the transcript and summary after the call',
        ],
        'tips' => [
            'Voice quality depends on your network — wired connections work best',
            'Sessions are recorded by default; toggle off in agent settings if needed',
            'STT and TTS are separate skills — costs accrue per provider',
        ],
        'prerequisites' => [
            ['label' => 'A voice-enabled agent', 'route' => 'agents.index'],
        ],
        'related' => [
            ['label' => 'Agents', 'route' => 'agents.index'],
        ],
    ],

    // ── Use Cases ───────────────────────────────────────────────────

    'use-cases.index' => [
        'title' => 'Use Cases',
        'description' => 'Curated playbooks demonstrating how FleetQ solves specific business problems — sales outreach, customer support automation, content QA, etc.',
        'steps' => [
            'Browse use cases by category',
            'Click any to see the full step-by-step playbook',
            'Install the supporting agents/skills/workflows with one click',
        ],
        'tips' => [
            'Use cases are the fastest path from "what does this do" to a working setup',
            'Each use case lists prerequisites — read those first',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Marketplace', 'route' => 'app.marketplace.index'],
            ['label' => 'Frameworks', 'route' => 'frameworks.index'],
        ],
    ],

    'use-cases.show' => [
        'title' => 'Use Case Detail',
        'description' => 'Step-by-step playbook for a single use case — required components, configuration, expected outcomes.',
        'steps' => [
            'Read the overview to confirm this fits your problem',
            'Review the prerequisites and required integrations',
            'Click "Install bundle" — agents / skills / workflows are forked into your team',
            'Run the example experiment to verify end-to-end',
        ],
        'tips' => [
            'Bundle install is reversible — uninstall removes the forked components',
            'After install, customize the agents and prompts to match your brand',
        ],
        'prerequisites' => [],
        'related' => [
            ['label' => 'Use Cases', 'route' => 'use-cases.index'],
        ],
    ],

];
