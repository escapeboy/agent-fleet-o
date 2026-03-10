<x-layouts.docs
    title="Quick Start"
    description="Run your first AI workflow in 5 minutes. Step-by-step guide to creating an agent, skill, and project in FleetQ."
    page="getting-started"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Quick Start — 5 Minutes</h1>
    <p class="mt-4 text-gray-600">
        In this guide you'll build a weekly competitor content summariser: every Monday morning, an AI agent reads
        competitor blog posts, summarises each one into 3 bullet points, and saves the results as a downloadable artifact.
    </p>

    <x-docs.callout type="tip">
        No LLM API key? You can use a local agent (Claude Code or Codex) at zero cost — FleetQ auto-detects them.
        See <a href="{{ route('docs.show', 'agents') }}" class="font-medium underline">Agents</a> for setup.
    </x-docs.callout>

    {{-- Step 1 --}}
    <x-docs.step number="1" title="Create an Agent">
        Go to <a href="/agents/create" class="font-medium text-primary-600 hover:underline">/agents/create</a>.
        Fill in:
        <ul class="mt-2 list-disc pl-5 text-sm">
            <li><strong>Name</strong>: Competitor Monitor</li>
            <li><strong>Role</strong>: Research Analyst</li>
            <li><strong>Goal</strong>: Summarise competitor blog posts into concise bullet points</li>
            <li><strong>Backstory</strong>: You are an expert technology journalist with 10 years of experience distilling complex content.</li>
            <li><strong>Provider</strong>: Leave as Default (inherits team setting)</li>
        </ul>
        Click <strong>Create Agent</strong>.
    </x-docs.step>

    {{-- Step 2 --}}
    <x-docs.step number="2" title="Create a Skill">
        Go to <a href="/skills/create" class="font-medium text-primary-600 hover:underline">/skills/create</a>.
        <ul class="mt-2 list-disc pl-5 text-sm">
            <li><strong>Name</strong>: Content Summariser</li>
            <li><strong>Type</strong>: LLM</li>
            <li><strong>Prompt</strong>: <code class="rounded bg-gray-100 px-1 text-xs">Summarise the following article into exactly 3 bullet points. Be concise and focus on the key takeaway:\n\n@{{ content }}</code></li>
        </ul>
        Click <strong>Save Skill</strong>. A version 1.0 is created automatically.
    </x-docs.step>

    {{-- Step 3 --}}
    <x-docs.step number="3" title="Assign the Skill to the Agent">
        Open the agent detail page (<a href="/agents" class="font-medium text-primary-600 hover:underline">/agents</a> → Competitor Monitor).
        Under the <strong>Skills</strong> tab, click <strong>Add Skill</strong> and select <em>Content Summariser</em>.
        Save. The agent now uses this skill when executing LLM calls.
    </x-docs.step>

    {{-- Step 4 --}}
    <x-docs.step number="4" title="Create a Project">
        Go to <a href="/projects/create" class="font-medium text-primary-600 hover:underline">/projects/create</a>.
        <ul class="mt-2 list-disc pl-5 text-sm">
            <li><strong>Name</strong>: Weekly Competitor Digest</li>
            <li><strong>Type</strong>: Continuous</li>
            <li><strong>Agent</strong>: Competitor Monitor</li>
            <li><strong>Goal</strong>: Summarise the top 5 competitor blog posts published this week</li>
            <li><strong>Schedule</strong>: Weekly — Monday at 08:00</li>
        </ul>
        Click <strong>Activate Project</strong>. FleetQ will run the agent every Monday at 08:00.
    </x-docs.step>

    {{-- Step 5 --}}
    <x-docs.step number="5" title="Watch it run">
        On the project detail page, click <strong>Trigger Run</strong> to test immediately.
        FleetQ creates an Experiment, runs it through the pipeline, and saves the output as an <strong>Artifact</strong>.
        You can preview, download, or set up an outbound connector to deliver results via email or Slack.
    </x-docs.step>

    {{-- API alternative --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Or use the API</h2>
    <p class="mt-2 text-sm text-gray-600">All UI actions have API equivalents. Create your API token in <strong>Team Settings → API Tokens</strong>, then:</p>

    <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/experiments') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Weekly Competitor Digest",
    "agent_id": "YOUR_AGENT_ID",
    "goal": "Summarise the top 5 competitor blog posts published this week"
  }'</x-docs.code>

    {{-- Next steps --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">What next?</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <a href="{{ route('docs.show', 'experiments') }}" class="group rounded-lg border border-gray-200 p-4 transition hover:border-primary-300 hover:bg-primary-50">
            <p class="font-medium text-gray-900 group-hover:text-primary-700">Experiments →</p>
            <p class="mt-1 text-sm text-gray-500">Learn about the 20-state pipeline, pausing, retrying, and artifacts.</p>
        </a>
        <a href="{{ route('docs.show', 'workflows') }}" class="group rounded-lg border border-gray-200 p-4 transition hover:border-primary-300 hover:bg-primary-50">
            <p class="font-medium text-gray-900 group-hover:text-primary-700">Workflows →</p>
            <p class="mt-1 text-sm text-gray-500">Build visual DAG workflows with conditional branches and human tasks.</p>
        </a>
        <a href="{{ route('docs.show', 'signals') }}" class="group rounded-lg border border-gray-200 p-4 transition hover:border-primary-300 hover:bg-primary-50">
            <p class="font-medium text-gray-900 group-hover:text-primary-700">Signals →</p>
            <p class="mt-1 text-sm text-gray-500">Trigger workflows automatically from webhooks, RSS, or CRM events.</p>
        </a>
        <a href="{{ route('docs.show', 'mcp-server') }}" class="group rounded-lg border border-gray-200 p-4 transition hover:border-primary-300 hover:bg-primary-50">
            <p class="font-medium text-gray-900 group-hover:text-primary-700">MCP Server →</p>
            <p class="mt-1 text-sm text-gray-500">Connect Claude Code or Cursor directly to FleetQ via 143 MCP tools.</p>
        </a>
    </div>
</x-layouts.docs>
