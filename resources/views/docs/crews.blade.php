<x-layouts.docs
    title="Crews"
    description="FleetQ Crews coordinate teams of AI agents. Learn about crew roles, process types, and how orchestration works under the hood."
    page="crews"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Crews — Multi-Agent Collaboration</h1>
    <p class="mt-4 text-gray-600">
        A <strong>Crew</strong> is a team of agents working together toward a shared goal. One agent decomposes
        the work, others execute their specialisations, and a reviewer validates the results before synthesis.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A PR agency uses a 3-agent crew to produce press releases.
        The Coordinator decomposes the brief, a Copywriter drafts the release, a Fact Checker reviews claims,
        and the Coordinator synthesises the final approved version.</em>
    </p>

    {{-- Roles --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Crew member roles</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Role</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Responsibility</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">coordinator</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Decomposes the goal into tasks and synthesises the final result. Every crew has exactly one coordinator.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">worker</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Executes assigned tasks. The workhorse of the crew.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">qa</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Validates output quality. Can request revisions before the coordinator synthesises.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Per-member permission policies --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Per-member permission policies</h2>
    <p class="mt-2 text-sm text-gray-600">
        Each crew member can be given an individual policy that constrains what the agent is allowed to do during
        execution. Policies are stored in the member's <code class="rounded bg-gray-100 px-1 text-xs">config</code>
        JSONB field and enforced by <code class="rounded bg-gray-100 px-1 text-xs">CrewOrchestrator</code> at runtime.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Field</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_allowlist</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Array of tool IDs the agent may use. If set, the agent cannot call tools outside this list.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">max_steps</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Maximum number of execution steps allowed for this member in a single crew run.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">max_credits</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Credit budget cap for this member. Execution stops when the member's spend reaches this limit.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.code lang="json">
// POST /api/v1/crews  (or PUT /api/v1/crews/CREW_ID)
{
  "name": "PR Agency Crew",
  "members": [
    {
      "agent_id": "agt_01jq...",
      "role": "worker",
      "workerConstraints": {
        "tool_allowlist": ["tool_web_search", "tool_fetch_url"],
        "max_steps": 20,
        "max_credits": 500
      }
    }
  ]
}</x-docs.code>

    {{-- Process types --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Process types</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Sequential</p>
            <p class="mt-1 text-sm text-gray-600">
                Agents execute one at a time. Each agent receives the previous agent's output.
                Predictable, auditable, but slower.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Parallel</p>
            <p class="mt-1 text-sm text-gray-600">
                All agents execute simultaneously on independent sub-tasks.
                Fastest option. Results merged by the coordinator.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Hierarchical</p>
            <p class="mt-1 text-sm text-gray-600">
                Tree structure. The coordinator assigns sub-tasks to workers who may delegate further.
                Best for complex, decomposable goals.
            </p>
        </div>
    </div>

    {{-- Running a crew --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Running a crew</h2>
    <p class="mt-2 text-sm text-gray-600">
        From the crew detail page, click <strong>Execute</strong> or navigate to
        <code class="rounded bg-gray-100 px-1 text-xs">/crews/{id}/execute</code>.
        Provide a goal and optional context. The crew starts immediately.
    </p>

    <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/crews/CREW_ID/execute') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "goal": "Write a press release for our Series B funding announcement",
    "context": "Amount: $12M. Lead investor: Acme Ventures. Use case: AI for e-commerce."
  }'</x-docs.code>

    {{-- Orchestration --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Under the hood: CrewOrchestrator</h2>
    <p class="mt-2 text-sm text-gray-600">
        The <code class="rounded bg-gray-100 px-1 text-xs">CrewOrchestrator</code> runs a 4-step loop:
    </p>
    <div class="mt-4 space-y-2">
        <x-docs.step number="1" title="DecomposeGoal">The coordinator agent breaks the goal into discrete tasks assigned to each crew member.</x-docs.step>
        <x-docs.step number="2" title="Execute tasks">Workers execute their assigned tasks (in parallel or sequentially, based on process type).</x-docs.step>
        <x-docs.step number="3" title="ValidateTaskOutput">The QA agent validates each output. Failed validations trigger a retry of that specific task.</x-docs.step>
        <x-docs.step number="4" title="SynthesizeResult">The coordinator merges all validated outputs into the final result and saves it as an Artifact.</x-docs.step>
    </div>

    {{-- Artifacts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Crew artifacts</h2>
    <p class="mt-2 text-sm text-gray-600">
        Each crew execution automatically collects artifacts from task outputs via <code class="rounded bg-gray-100 px-1 text-xs">CollectCrewArtifactsAction</code>.
        View and download artifacts from the execution detail page.
    </p>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach([
                    ['crew_list', 'List crews with filtering'],
                    ['crew_get', 'Get crew details including members'],
                    ['crew_create', 'Create a new crew with members'],
                    ['crew_update', 'Update crew configuration'],
                    ['crew_execute', 'Start a crew execution'],
                    ['crew_execution_status', 'Check execution progress'],
                    ['crew_executions_list', 'List past executions'],
                ] as [$tool, $desc])
                <tr>
                    <td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">{{ $tool }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">{{ $desc }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- API --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach([
                    ['GET', '/api/v1/crews', 'List crews'],
                    ['GET', '/api/v1/crews/{id}', 'Get crew details'],
                    ['POST', '/api/v1/crews', 'Create crew'],
                    ['PUT', '/api/v1/crews/{id}', 'Update crew'],
                    ['DELETE', '/api/v1/crews/{id}', 'Delete crew'],
                    ['POST', '/api/v1/crews/{id}/execute', 'Start execution'],
                    ['GET', '/api/v1/crews/{id}/executions', 'List executions'],
                    ['GET', '/api/v1/crews/{id}/executions/{eid}', 'Execution detail'],
                ] as [$method, $path, $desc])
                <tr>
                    <td class="py-2 pl-4 pr-6"><span class="rounded bg-{{ $method === 'GET' ? 'green' : ($method === 'POST' ? 'blue' : ($method === 'DELETE' ? 'red' : 'yellow')) }}-100 px-1.5 py-0.5 font-mono text-xs font-medium text-{{ $method === 'GET' ? 'green' : ($method === 'POST' ? 'blue' : ($method === 'DELETE' ? 'red' : 'yellow')) }}-700">{{ $method }}</span></td>
                    <td class="py-2 pr-6 font-mono text-xs text-gray-600">{{ $path }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">{{ $desc }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        See also: <a href="{{ route('docs.show', 'agents') }}" class="font-medium underline">Agents</a> (individual AI workers),
        <a href="{{ route('docs.show', 'workflows') }}" class="font-medium underline">Workflows</a> (visual DAG pipelines).
    </x-docs.callout>
</x-layouts.docs>
