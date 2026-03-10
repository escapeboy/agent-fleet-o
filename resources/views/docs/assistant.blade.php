<x-layouts.docs
    title="AI Assistant"
    description="The FleetQ AI Assistant is a context-aware co-pilot embedded in every page. Learn what it can do, how it uses tools, and how to get the most from it."
    page="assistant"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">AI Assistant — Your Platform Co-Pilot</h1>
    <p class="mt-4 text-gray-600">
        The <strong>AI Assistant</strong> is a context-aware chat panel embedded in every page of the app.
        Ask it anything about your data, have it create or update entities, or delegate platform operations —
        all in natural language.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>You're reviewing last week's experiments and ask the assistant:
        "How is the weekly digest project doing? How much did it spend this month?"
        The assistant queries your live data and responds with the current run count, success rate, and exact credit usage.</em>
    </p>

    {{-- Where to find it --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Where to find it</h2>
    <p class="mt-2 text-sm text-gray-600">
        The assistant panel is accessible from every page via the floating button in the bottom-right corner.
        Click it to open a full-width chat interface. It opens in the context of whatever page you're currently viewing.
    </p>

    {{-- Context awareness --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Context awareness</h2>
    <p class="mt-2 text-sm text-gray-600">
        When you open the assistant on a specific page, it automatically receives the entity's context:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li>On an <strong>experiment page</strong>: knows the experiment ID, status, and stage history</li>
        <li>On a <strong>project page</strong>: knows the project's schedule, recent runs, and budget</li>
        <li>On an <strong>agent page</strong>: knows the agent's config, skills, and health status</li>
        <li>On the <strong>dashboard</strong>: receives the KPI summary (runs, costs, agent health)</li>
    </ul>
    <p class="mt-2 text-sm text-gray-600">
        You can reference the current entity naturally: <em>"Retry this experiment from step 3"</em> —
        the assistant knows which experiment "this" refers to.
    </p>

    {{-- What it can do --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">What the assistant can do</h2>
    <p class="mt-2 text-sm text-gray-600">The assistant has access to 28 internal tools, role-gated by your team role:</p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Category</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Role required</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Examples</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Read</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-500">Any</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List experiments, get agent status, query budget, search audit log</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Create / Update</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-500">Member+</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create agents, start experiments, update skills, trigger project runs</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Control</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-500">Member+</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Pause, resume, retry, kill experiments</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Delete / Destructive</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-500">Admin+</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete agents, remove team members, purge semantic cache</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Tool loop --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Under the hood: the tool loop</h2>
    <p class="mt-2 text-sm text-gray-600">
        When you send a message, the assistant sends it to the LLM along with a list of available tools.
        The model decides which tools to call, calls them (against your live data), and loops until it
        has enough information to answer. Complex queries may invoke 4–6 tools internally.
    </p>

    <x-docs.callout type="info">
        The assistant uses a sliding context window of <strong>30 messages (~50k tokens)</strong>.
        Older messages are summarised automatically as the conversation grows, so you never lose context.
    </x-docs.callout>

    {{-- Example prompts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Example prompts</h2>
    <div class="mt-3 grid gap-2">
        @foreach([
            'Show me all failed experiments from the last 7 days',
            'Create a new agent called "Lead Qualifier" with a sales-focused goal',
            'How much credit did we spend on the blog content project this month?',
            'Retry the last failed experiment from the scoring step',
            'What agents are currently unhealthy?',
            'List all pending approval requests and their deadlines',
        ] as $prompt)
        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5">
            <span class="mt-0.5 text-gray-400" aria-hidden="true">→</span>
            <p class="text-sm text-gray-700 italic">"{{ $prompt }}"</p>
        </div>
        @endforeach
    </div>
</x-layouts.docs>
