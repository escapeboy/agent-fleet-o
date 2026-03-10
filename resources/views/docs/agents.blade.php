<x-layouts.docs
    title="Agents"
    description="FleetQ agents are AI workers defined by role, goal, and backstory. Learn how to configure agents, choose LLM providers, and attach skills."
    page="agents"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Agents — Your AI Workforce</h1>
    <p class="mt-4 text-gray-600">
        An <strong>Agent</strong> is a named AI worker configured with a role, goal, and backstory. These three fields
        are injected into the LLM system prompt to shape how the model reasons and responds.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>An e-commerce team runs three agents: a "Product Researcher" who finds trending items,
        a "Copywriter" who writes listing descriptions, and a "Quality Checker" who flags inaccurate claims.</em>
    </p>

    {{-- Role / Goal / Backstory --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Role, Goal, and Backstory</h2>
    <p class="mt-2 text-sm text-gray-600">These three fields determine the agent's identity inside the LLM context window:</p>

    <div class="mt-4 space-y-3">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Role</p>
            <p class="mt-1 text-sm text-gray-600">
                The agent's job title. Establishes authority and scope.
                <span class="block mt-1 font-mono text-xs text-gray-500">Example: "Senior Product Researcher"</span>
            </p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Goal</p>
            <p class="mt-1 text-sm text-gray-600">
                What the agent is trying to achieve. Guides task selection and output format.
                <span class="block mt-1 font-mono text-xs text-gray-500">Example: "Find the top 10 trending products in the electronics category and return a ranked list with rationale."</span>
            </p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Backstory</p>
            <p class="mt-1 text-sm text-gray-600">
                Persona detail that sets tone, expertise level, and communication style.
                <span class="block mt-1 font-mono text-xs text-gray-500">Example: "You have 8 years of experience in consumer electronics retail. You make data-driven recommendations backed by market trends."</span>
            </p>
        </div>
    </div>

    {{-- Provider selection --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Provider selection</h2>
    <p class="mt-2 text-sm text-gray-600">
        Each agent can use a different LLM. FleetQ supports Anthropic (Claude), OpenAI (GPT-4o), Google (Gemini),
        and local agents (Claude Code, Codex). If you leave it as <em>Default</em>, FleetQ inherits the team-level
        setting.
    </p>

    <x-docs.callout type="tip">
        Local agents (Claude Code, Codex) are auto-detected on the host machine and cost <strong>zero credits</strong>.
        They're perfect for development and testing without consuming your API budget.
    </x-docs.callout>

    <p class="mt-3 text-sm text-gray-600"><strong>Resolution hierarchy</strong> (highest to lowest priority):</p>
    <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
        <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs">Skill</span>
        <span class="text-gray-400">→</span>
        <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs">Agent</span>
        <span class="text-gray-400">→</span>
        <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs">Team</span>
        <span class="text-gray-400">→</span>
        <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs">Platform default</span>
    </div>
    <p class="mt-2 text-xs text-gray-500">If a skill specifies its own model, that takes precedence over the agent's setting.</p>

    {{-- Skills and tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Attaching skills and tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        From the agent detail page, add <strong>Skills</strong> (reusable AI capabilities) and <strong>Tools</strong>
        (MCP servers, built-in bash/filesystem/browser). At inference time, FleetQ injects these into the LLM call
        automatically.
    </p>

    {{-- Health checks --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Health checks (behind the scenes)</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every 5 minutes, the platform runs a silent health check on all active agents. If an agent fails
        (e.g. the underlying model is unreachable), its status is set to <strong>degraded</strong>.
        Degraded agents are flagged in the UI and excluded from new experiment assignments until a subsequent
        health check passes. Healthy agents show no indicator.
    </p>

    {{-- Disabling --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Disabling an agent</h2>
    <p class="mt-2 text-sm text-gray-600">
        Soft-disabling an agent prevents it from being used in new experiments. Existing running experiments
        are not affected. Re-enable at any time from the agent detail page or via:
    </p>

    <x-docs.code lang="bash">
curl -X PATCH {{ url('/api/v1/agents/AGENT_ID/status') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"status": "disabled"}'</x-docs.code>
</x-layouts.docs>
