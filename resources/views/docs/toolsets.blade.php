<x-layouts.docs
    title="Toolsets"
    description="Group tools into named collections and attach them to agents in one step — no more wiring the same 10 tools to every agent manually."
    page="toolsets"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Toolsets</h1>
    <p class="mt-4 text-gray-600">
        A <strong>Toolset</strong> is a named, reusable collection of <a href="{{ route('docs.show', 'tools') }}" class="text-primary-600 hover:underline">Tools</a>.
        Instead of attaching ten individual tools to every agent that needs them, you create a toolset once —
        <em>"Research Stack"</em>, <em>"Dev Utilities"</em>, <em>"Data Pipeline"</em> — and attach the whole set
        in a single click. Every agent that uses the toolset automatically gets any tools you add to it later.
    </p>

    {{-- Core concepts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Core concepts</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Toolset</p>
            <p class="mt-1 text-sm text-gray-600">
                A team-scoped collection of tools with a name and optional description.
                Toolsets are versioned — adding or removing a tool from a toolset takes effect immediately
                for all agents that use it.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">agent_toolset pivot</p>
            <p class="mt-1 text-sm text-gray-600">
                Agents reference toolsets via the <code class="font-mono text-xs">agent_toolset</code> pivot table.
                At execution time, <code class="font-mono text-xs">ResolveAgentToolsAction</code> expands all
                attached toolsets into the flat tool list the agent actually uses.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Semantic tool selection</p>
            <p class="mt-1 text-sm text-gray-600">
                When an agent has more than 15 active tools, FleetQ automatically narrows the tool list
                using pgvector cosine similarity (&gt;0.75) against the current task description —
                so the LLM receives only the most relevant tools for each step.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Priority &amp; overrides</p>
            <p class="mt-1 text-sm text-gray-600">
                Each toolset attachment can carry per-agent credential overrides, so the same
                "GitHub" toolset can use a different token for different agents without duplicating the toolset.
            </p>
        </div>
    </div>

    {{-- Workflow --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Typical workflow</h2>
    <ol class="mt-3 list-inside list-decimal space-y-2 text-sm text-gray-600">
        <li><strong>Create a toolset</strong> — go to <code class="font-mono text-xs">/toolsets/create</code>, give it a name, and add tools from your library.</li>
        <li><strong>Attach to agents</strong> — open any agent and select the toolset under <em>Tools</em>. One click replaces manual tool-by-tool wiring.</li>
        <li><strong>Update once, applies everywhere</strong> — add a new tool to the toolset and every agent using it picks it up automatically on the next run.</li>
    </ol>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">toolset_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all toolsets for the team.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">toolset_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Get details of a specific toolset including its tool members.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">toolset_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new toolset with a name, description, and initial tool IDs.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">toolset_update</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update toolset name, description, or tool membership.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">toolset_delete</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a toolset (does not delete the underlying tools).</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Related --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Related concepts</h2>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><a href="{{ route('docs.show', 'tools') }}" class="text-primary-600 hover:underline">Tools</a> — the individual MCP and built-in tools that make up a toolset.</li>
        <li><a href="{{ route('docs.show', 'agents') }}" class="text-primary-600 hover:underline">Agents</a> — attach toolsets on the agent detail page under <em>Tools</em>.</li>
        <li><a href="{{ route('docs.show', 'credentials') }}" class="text-primary-600 hover:underline">Credentials</a> — per-agent credential overrides on toolset attachments.</li>
    </ul>
</x-layouts.docs>
