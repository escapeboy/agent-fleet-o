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

];
