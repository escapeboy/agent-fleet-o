<x-layouts.docs
    title="Workflows"
    description="Build visual DAG workflows in FleetQ with 8 node types: agent, conditional, human task, switch, dynamic fork, do-while, and more."
    page="workflows"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Workflows — Visual DAG Builder</h1>
    <p class="mt-4 text-gray-600">
        A <strong>Workflow</strong> is a directed acyclic graph (DAG) of nodes that defines exactly how a
        multi-step AI process should execute. Build it visually, describe it in plain English and let AI
        generate it, or define it via the API.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>An HR team automates candidate screening.
        The workflow ingests a CV → extracts skills → scores the candidate against the job requirements →
        routes high-scorers to a human reviewer → sends offer letters automatically.</em>
    </p>

    {{-- Node types --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Node types</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Node</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">start</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Entry point. Every workflow has exactly one start node.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Run an AI agent with a specific goal. Output is available to downstream nodes.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">conditional</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Two-way branch: evaluates an expression and routes to true or false path.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">switch</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Multi-way branch: matches a value against multiple cases, routes to matching edge.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">human_task</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Pauses the workflow and waits for a human to complete a form. SLA timer enforced.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">dynamic_fork</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Fan-out: iterates over a list and runs downstream nodes once per item in parallel.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">do_while</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Loop: repeats a sub-graph until an exit condition is met.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">end</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Terminal node. Triggers artifact collection and marks the experiment complete.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Conditional expressions --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Conditional expressions</h2>
    <p class="mt-2 text-sm text-gray-600">
        Conditional and switch nodes evaluate expressions against the current step's output.
        Use <code class="rounded bg-gray-100 px-1 text-xs">{{'{{'}}variable{{'}}'}}</code> syntax to reference upstream node output:
    </p>

    <x-docs.code lang="text">
{{-- Route high-scoring candidates to fast-track --}}
{{ '{{output.score}} > 80' }}

{{-- Check if the summary was generated --}}
{{ '{{output.summary}} is not empty' }}

{{-- Switch on candidate seniority --}}
{{ '{{output.level}}' }}   {{-- matches "junior" | "mid" | "senior" --}}</x-docs.code>

    {{-- Human task nodes --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Human Task nodes</h2>
    <p class="mt-2 text-sm text-gray-600">
        When the workflow reaches a <code class="rounded bg-gray-100 px-1 text-xs">human_task</code> node,
        FleetQ creates an <strong>ApprovalRequest</strong> and pauses execution. A notification is sent to the
        designated reviewer, who fills in a form (defined as a JSON schema on the node).
    </p>

    <x-docs.callout type="info">
        Human Task nodes enforce an SLA timer. If the reviewer doesn't act within the configured duration,
        the task is automatically escalated (to a fallback reviewer) or expired (and the experiment fails gracefully).
    </x-docs.callout>

    {{-- AI generation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Generate workflows from text</h2>
    <p class="mt-2 text-sm text-gray-600">
        Describe your workflow in plain English, and FleetQ uses Claude to generate the DAG for you:
    </p>

    <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/workflows/WORKFLOW_ID/generate') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "prompt": "Ingest a job application, extract candidate skills, score fit against the role, then send a human review request if score > 70"
  }'</x-docs.code>

    {{-- Cost estimation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Estimating cost before you run</h2>
    <p class="mt-2 text-sm text-gray-600">
        Before activating a workflow, check the projected cost:
    </p>

    <x-docs.code lang="bash">
GET {{ url('/api/v1/workflows/WORKFLOW_ID/cost') }}</x-docs.code>

    <p class="mt-2 text-sm text-gray-600">Returns token estimates per node, total credit estimate, and a breakdown by provider.</p>
</x-layouts.docs>
