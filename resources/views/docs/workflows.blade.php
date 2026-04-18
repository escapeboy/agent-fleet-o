<x-layouts.docs
    title="Workflows"
    description="Build visual DAG workflows in FleetQ with 19 node types: agent, crew, LLM, conditional, switch, human task, dynamic fork, do-while, sub-workflow, HTTP request, and more."
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">llm</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Direct LLM call without a full agent. Supports four operations: <code class="rounded bg-gray-100 px-1">text_complete</code>, <code class="rounded bg-gray-100 px-1">extract</code>, <code class="rounded bg-gray-100 px-1">embed</code>, and <code class="rounded bg-gray-100 px-1">search</code>. See LlmNode operations below.</td>
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
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Fan-out: iterates over a list and runs downstream nodes once per item in parallel. When the target is a <code class="rounded bg-gray-100 px-1">sub_workflow</code> node, each branch executes as a separate sub-workflow run; all branches fan back in via a <code class="rounded bg-gray-100 px-1">merge</code> node before execution continues.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">do_while</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Loop: repeats a sub-graph until an exit condition is met.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">merge</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Fan-in: waits for all parallel branches to complete and merges their outputs into a single structured result.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">crew</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Run a multi-agent Crew. The crew executes and its synthesised result is available to downstream nodes.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">sub_workflow</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Embed another workflow as a reusable sub-graph. The sub-workflow runs as a nested experiment.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">http_request</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Make an HTTP request to an external API. Response body and status code are available to downstream nodes.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">time_gate</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Pause execution until a specified time or duration elapses, then continue with the original data.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">knowledge_retrieval</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Semantic search over the team's memory and knowledge base. Returns top-k relevant chunks as structured output.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">parameter_extractor</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Extract named parameters from unstructured text using LLM-assisted parsing. Outputs a structured JSON object.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">variable_aggregator</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Collect outputs from multiple upstream nodes and merge them into a single aggregated result.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">template_transform</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Render a Blade/Twig template with upstream variables to produce formatted text output.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">annotation</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Non-executing sticky note attached to the canvas. Use for design comments, TODOs, or reviewer guidance — skipped at runtime.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">iteration</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Flowise-style iteration block: runs an inner sub-graph once per item in an input collection with accumulated state. A simpler alternative to <code class="rounded bg-gray-100 px-1">dynamic_fork</code> + <code class="rounded bg-gray-100 px-1">merge</code> when you want strictly sequential per-item execution.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">workflow_ref</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Alias of <code class="rounded bg-gray-100 px-1">sub_workflow</code> using Flowise naming. Embeds a reusable child workflow as a single node.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">end</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Terminal node. Triggers artifact collection and marks the experiment complete.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- LlmNode operations --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">LlmNode operations</h2>
    <p class="mt-2 text-sm text-gray-600">
        An <code class="rounded bg-gray-100 px-1 text-xs">llm</code> node is configured with an <code class="rounded bg-gray-100 px-1 text-xs">operation</code> field that selects one of four modes:
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Operation</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What it does</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">text_complete</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Default. Sends a prompt and stores the text response as node output.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">extract</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Structured extraction. Requires <code class="rounded bg-gray-100 px-1">output_schema</code> (JSON Schema). Returns a typed JSON object.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">embed</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Generates a float[] embedding vector from the input text. Stored as node output for downstream similarity search.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">search</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Semantic memory search. Runs a cosine-similarity query against the team's memory store and returns top-k results.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-sm text-gray-600">Node config examples:</p>

    <x-docs.code lang="json">
// extract — pull structured data from unstructured text
{
  "operation": "extract",
  "model": "claude-sonnet-4-5",
  "prompt_template": "Extract candidate details from: @{{input.cv_text}}",
  "output_schema": {
    "type": "object",
    "properties": {
      "name": { "type": "string" },
      "skills": { "type": "array", "items": { "type": "string" } },
      "years_experience": { "type": "integer" }
    },
    "required": ["name", "skills"]
  }
}</x-docs.code>

    <x-docs.code lang="json">
// embed — generate a vector for similarity search
{
  "operation": "embed",
  "text_template": "@{{output.summary}}",
  "embed_provider": "openai",
  "embed_model": "text-embedding-3-small"
}</x-docs.code>

    <x-docs.code lang="json">
// search — retrieve relevant memory entries
{
  "operation": "search",
  "query_template": "@{{input.candidate_skills}}",
  "search_k": 5
}</x-docs.code>

    {{-- Conditional expressions --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Conditional expressions</h2>
    <p class="mt-2 text-sm text-gray-600">
        Conditional and switch nodes evaluate expressions against the current step's output.
        Use <code class="rounded bg-gray-100 px-1 text-xs">@{{variable}}</code> syntax to reference upstream node output:
    </p>

    <x-docs.code lang="text">
{{-- Route high-scoring candidates to fast-track --}}
@{{output.score}} > 80

{{-- Check if the summary was generated --}}
@{{output.summary}} is not empty

{{-- Switch on candidate seniority --}}
@{{output.level}}   {{-- matches "junior" | "mid" | "senior" --}}</x-docs.code>

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

    {{-- Saga / compensation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Saga pattern (compensation on failure)</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every workflow node can declare a <strong>compensation node</strong> — a rollback step that runs
        automatically if the node fails mid-execution. This implements the
        <a href="https://microservices.io/patterns/data/saga.html" class="text-primary-600 underline">Saga pattern</a>
        for distributed transactions.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        Set <code class="rounded bg-gray-100 px-1 text-xs">compensation_node_id</code> on any node when saving the
        graph. On failure, <code class="rounded bg-gray-100 px-1 text-xs">RunCompensationChainAction</code> walks the
        executed nodes in reverse order and runs each compensation node in sequence.
    </p>

    <x-docs.code lang="json">
// PUT /api/v1/workflows/WORKFLOW_ID/graph
{
  "nodes": [
    {
      "id": "node-charge",
      "type": "agent",
      "label": "Charge customer",
      "compensation_node_id": "node-refund"
    },
    {
      "id": "node-refund",
      "type": "agent",
      "label": "Refund customer",
      "config": { "goal": "Reverse the charge for order @{{input.order_id}}" }
    }
  ]
}</x-docs.code>

    <x-docs.callout type="info">
        Compensation nodes are skipped if the original node never ran (e.g. it was never reached). Only nodes
        that successfully started execution are compensated.
    </x-docs.callout>

    {{-- Workflow Gateway --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Workflow Gateway — expose workflows as MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        The <strong>Workflow Gateway</strong> publishes any active workflow as a named MCP tool, making it
        callable by agents, the platform assistant, and external MCP clients.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">MCP tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">workflow_enable_gateway</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Publish a workflow as a named MCP tool. Requires <code class="rounded bg-gray-100 px-1">tool_name</code> (snake_case, 3–64 chars) and optional <code class="rounded bg-gray-100 px-1">mcp_execution_mode</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">workflow_disable_gateway</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Remove the MCP tool registration for a workflow.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">workflow_list_gateway_tools</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all workflows currently exposed as MCP tools.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-sm text-gray-600"><strong>Execution modes:</strong></p>
    <div class="mt-2 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Mode</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Behaviour</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">async</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Default. The MCP tool call returns immediately with an experiment ID; execution runs in the background.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">sync</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">The MCP tool call blocks until the workflow completes and returns the final output.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.code lang="json">
// Enable gateway via MCP (tool: workflow_enable_gateway)
{
  "workflow_id": "wf_01jq...",
  "tool_name": "screen_candidate",
  "mcp_execution_mode": "async"
}</x-docs.code>

    {{-- AI generation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Generate workflows from text</h2>
    <p class="mt-2 text-sm text-gray-600">
        Describe your workflow in plain English, and FleetQ uses Claude to generate the DAG for you.
        Generation is available via the visual builder UI (the ✨ button on the canvas) or through the
        <code class="rounded bg-gray-100 px-1 text-xs">workflow_generate</code> MCP tool:
    </p>

    <x-docs.code lang="json">
// MCP tool: workflow_generate
{
  "workflow_id": "wf_01jq...",
  "prompt": "Ingest a job application, extract candidate skills, score fit against the role, then send a human review request if score > 70"
}</x-docs.code>

    {{-- Observability --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Observability — LangFuse &amp; LangSmith</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every workflow can be instrumented with an external LLM observability provider. Attach credentials
        for <strong>LangFuse</strong> or <strong>LangSmith</strong> per workflow (or globally per team), and
        FleetQ will stream traces, spans, and generations to that provider in real time. Use it to debug failing
        runs, compare cost/latency across workflow revisions, and build dashboards across all AI activity in
        your organisation.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Provider</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What gets sent</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">LangFuse</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Trace per experiment, span per node, generation per LLM call. Token counts, latency, cost, prompt/response bodies, and workflow metadata are included.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">LangSmith</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Run tree with the workflow as root and nodes as children. Inputs, outputs, and errors are attached.</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-sm text-gray-600">
        Combine observability with the <a href="{{ route('docs.show', 'evaluation') }}" class="text-primary-600 hover:underline">Flow Evaluation Suite</a>
        for a closed loop: run an evaluation, inspect per-row traces in LangFuse/LangSmith, fix the failing
        node, re-run the evaluation, and compare deltas side-by-side.
    </p>

    {{-- Cost estimation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Estimating cost before you run</h2>
    <p class="mt-2 text-sm text-gray-600">
        Before activating a workflow, check the projected cost:
    </p>

    <x-docs.code lang="bash">
GET {{ url('/api/v1/workflows/WORKFLOW_ID/cost') }}</x-docs.code>

    <p class="mt-2 text-sm text-gray-600">Returns token estimates per node, total credit estimate, and a breakdown by provider.</p>
</x-layouts.docs>
