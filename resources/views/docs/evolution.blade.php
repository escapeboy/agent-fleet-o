<x-layouts.docs
    title="Evolution"
    description="Understand how FleetQ's agent self-improvement system generates, reviews, and applies proposals to continuously optimize agent configurations, skills, and workflows."
    page="evolution"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Evolution — Agent Self-Improvement</h1>
    <p class="mt-4 text-gray-600">
        <strong>Evolution</strong> is FleetQ's continuous optimization loop. Based on experiment results and
        performance metrics, the platform generates proposals to improve agent configurations, skills, and
        workflows — and lets your team review them before any changes are applied.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>An agent's average cost per run has crept up 40% over two weeks.
        Evolution analyzes the pattern, identifies an over-specified system prompt, and proposes a leaner
        alternative — including an estimated cost saving. A team admin reviews and applies it in one click.</em>
    </p>

    {{-- How it Works --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">How it works</h2>
    <p class="mt-2 text-sm text-gray-600">
        After experiments complete, the system analyzes performance metrics, cost data, and output quality.
        It generates <code>EvolutionProposal</code> records suggesting targeted improvements: better prompts,
        different models, adjusted parameters, or new skill combinations. Each proposal includes a rationale,
        expected impact, and risk level so reviewers have full context before deciding.
    </p>

    {{-- Proposal Lifecycle --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Proposal lifecycle</h2>
    <p class="mt-2 text-sm text-gray-600">
        Proposals move through three states. No changes are applied without an explicit human decision.
    </p>

    <div class="mt-4 overflow-hidden rounded-lg border border-gray-200">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">State</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">pending</td>
                    <td class="px-4 py-3 text-gray-600">Generated and waiting for analysis or team review.</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">analyzed</td>
                    <td class="px-4 py-3 text-gray-600">Analysis complete; proposal is ready to apply or reject.</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">applied / rejected</td>
                    <td class="px-4 py-3 text-gray-600">Terminal state — changes were applied or the proposal was dismissed.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Reviewing Proposals --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Reviewing proposals</h2>
    <p class="mt-2 text-sm text-gray-600">
        Navigate to <strong>Evolution</strong> in the sidebar (or <code>/api/v1/evolution</code> via API)
        to see all open proposals. Each proposal shows:
    </p>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><strong>What changed</strong> — a diff of the proposed configuration.</li>
        <li><strong>Why</strong> — the performance signal that triggered the suggestion.</li>
        <li><strong>Expected impact</strong> — projected cost saving, quality improvement, or latency reduction.</li>
        <li><strong>Risk level</strong> — low / medium / high, based on the scope of the change.</li>
    </ul>
    <p class="mt-3 text-sm text-gray-600">
        Click <strong>Apply</strong> to commit the change or <strong>Reject</strong> to dismiss it.
        Only Admin and Owner roles can apply or reject proposals.
    </p>

    {{-- Analyzing Performance --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Analyzing performance</h2>
    <p class="mt-2 text-sm text-gray-600">
        The <code>evolution_analyze</code> MCP tool triggers on-demand analysis for a specific agent or
        experiment. The system examines:
    </p>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li>Response quality scores from evaluations</li>
        <li>Cost per run over time</li>
        <li>Latency (p50 / p95)</li>
        <li>Error and retry rates</li>
        <li>User feedback signals</li>
    </ul>

    <x-docs.code lang="bash" title="Trigger analysis via MCP">
# Analyze a specific agent
evolution_analyze agent_id=YOUR_AGENT_ID

# Analyze a completed experiment
evolution_analyze experiment_id=YOUR_EXPERIMENT_ID</x-docs.code>

    {{-- Applying Proposals --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Applying proposals</h2>
    <p class="mt-2 text-sm text-gray-600">
        <code>evolution_apply</code> applies the proposed changes to the agent or skill configuration.
        Changes are versioned and reversible — use agent config history or skill versions to roll back
        if the applied change doesn't perform as expected.
    </p>

    <x-docs.code lang="json" title="Apply via API">
POST /api/v1/evolution/{id}/apply</x-docs.code>

    <x-docs.callout type="tip">
        Applied changes create a new entry in the agent's config history. Use
        <code>GET /api/v1/agents/{id}/config-history</code> or the <code>agent_rollback</code>
        MCP tool to revert if needed.
    </x-docs.callout>

    {{-- MCP Tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <div class="mt-4 overflow-hidden rounded-lg border border-gray-200">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Tool</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">evolution_proposal_list</td>
                    <td class="px-4 py-3 text-gray-600">List all evolution proposals, optionally filtered by status.</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">evolution_analyze</td>
                    <td class="px-4 py-3 text-gray-600">Trigger performance analysis for an agent or experiment.</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">evolution_apply</td>
                    <td class="px-4 py-3 text-gray-600">Apply a proposal's suggested changes to the target entity.</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">evolution_reject</td>
                    <td class="px-4 py-3 text-gray-600">Reject a proposal and mark it as dismissed.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- API Endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
    <div class="mt-4 overflow-hidden rounded-lg border border-gray-200">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Method</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Path</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">GET</td>
                    <td class="px-4 py-3 font-mono text-gray-800">/api/v1/evolution</td>
                    <td class="px-4 py-3 text-gray-600">List all evolution proposals (cursor-paginated).</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">GET</td>
                    <td class="px-4 py-3 font-mono text-gray-800">/api/v1/evolution/{id}</td>
                    <td class="px-4 py-3 text-gray-600">Retrieve a single proposal with full diff and rationale.</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">POST</td>
                    <td class="px-4 py-3 font-mono text-gray-800">/api/v1/evolution/{id}/apply</td>
                    <td class="px-4 py-3 text-gray-600">Apply the proposal's changes to the target entity.</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-mono text-gray-800">POST</td>
                    <td class="px-4 py-3 font-mono text-gray-800">/api/v1/evolution/{id}/reject</td>
                    <td class="px-4 py-3 text-gray-600">Reject and dismiss the proposal.</td>
                </tr>
            </tbody>
        </table>
    </div>
</x-layouts.docs>
