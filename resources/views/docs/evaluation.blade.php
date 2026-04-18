<x-layouts.docs
    title="Evaluation"
    description="Evaluate workflows and agents with the Flow Evaluation Suite — LLM-as-judge scoring, regression datasets, and reproducible quality checks."
    page="evaluation"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Evaluation — LLM-as-Judge Quality Gates</h1>
    <p class="mt-4 text-gray-600">
        The <strong>Evaluation</strong> domain gives you a reproducible way to measure the quality of your
        workflows, agents, and crews. Store test cases in a dataset, run any workflow against them, and score
        the outputs with an LLM judge. Use it for regression testing before a deploy, for A/B comparing two
        prompts, or for catching quality drift over time.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A team owns a "summarise support ticket" workflow. They capture 50
        real tickets with expected summaries as a dataset. Before every change to the underlying agent, they
        re-run the evaluation and reject the change if the average judge score drops below 0.85.</em>
    </p>

    {{-- Core concepts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Core concepts</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">EvaluationDataset</p>
            <p class="mt-1 text-sm text-gray-600">
                A collection of rows, each with an input payload and an expected output. Datasets are team-scoped
                and versioned — you can edit rows, add new examples, and freeze versions for reproducible runs.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Flow evaluation run</p>
            <p class="mt-1 text-sm text-gray-600">
                A single pass of a workflow against every row in a dataset. Each row produces an actual output;
                the judge model scores it against the expected output and stores per-row plus aggregate metrics.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">LLM judge</p>
            <p class="mt-1 text-sm text-gray-600">
                Any configured LLM (default <code class="font-mono text-xs">claude-haiku-4-5</code>) can be used
                as a judge. Pass a custom scoring prompt with <code class="font-mono text-xs">&#123;expected&#125;</code>
                and <code class="font-mono text-xs">&#123;actual&#125;</code> placeholders, or use the built-in
                similarity rubric.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Regression gates</p>
            <p class="mt-1 text-sm text-gray-600">
                A dataset can carry a target score. Runs that fall below the target fail loudly — wire them into
                your CI pipeline or a pre-deploy approval to block quality drops from shipping.
            </p>
        </div>
    </div>

    {{-- Lifecycle --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Evaluation lifecycle</h2>
    <ol class="mt-3 list-inside list-decimal space-y-2 text-sm text-gray-600">
        <li><strong>Build a dataset</strong> — curate a handful of representative rows. Real production inputs work best.</li>
        <li><strong>Pick a workflow</strong> — any published workflow can be scored. No code changes required.</li>
        <li><strong>Choose a judge</strong> — default is <code class="font-mono text-xs">claude-haiku-4-5-20251001</code>, or supply your own judge prompt for domain-specific rubrics.</li>
        <li><strong>Run</strong> — <code class="font-mono text-xs">RunFlowEvaluationAction</code> fans out one workflow execution per row, then scores each result.</li>
        <li><strong>Review</strong> — inspect per-row scores in the Evaluation panel. Failing rows surface first so you can diagnose quickly.</li>
    </ol>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        Agents and the AI assistant can manage evaluations directly via the MCP server. Five tools cover the
        full lifecycle — dataset CRUD, running evaluations, and fetching results.
    </p>
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">evaluation_dataset_manage</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Consolidated CRUD for generic evaluation datasets (list, get, create, update, delete, add_row, remove_row).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">evaluation_run</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Run a generic evaluation over a dataset (non-workflow scenarios).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">flow_evaluation_dataset_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a workflow-specific evaluation dataset with a name and optional description.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">flow_evaluation_run_start</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Start a flow evaluation run against a dataset with an optional custom judge model and prompt.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">flow_evaluation_results</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve per-row actual output + judge score + aggregate metrics for a run.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.code lang="json" title="Start a flow evaluation via MCP">
flow_evaluation_run_start({
  "dataset_id": "018f1a2b-...",
  "workflow_id": "018f1a2c-...",
  "judge_model": "claude-haiku-4-5-20251001",
  "judge_prompt": "Score how faithfully the actual summary captures the key points of the expected summary. 0.0–1.0. Expected:\n{expected}\n\nActual:\n{actual}"
})</x-docs.code>

    {{-- Regression testing --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Regression testing pattern</h2>
    <p class="mt-2 text-sm text-gray-600">
        Wire flow evaluation into your deployment workflow:
    </p>
    <ol class="mt-3 list-inside list-decimal space-y-1.5 text-sm text-gray-600">
        <li>Maintain a <strong>golden dataset</strong> per critical workflow.</li>
        <li>After any agent, skill, or workflow change, trigger <code class="font-mono text-xs">flow_evaluation_run_start</code> via MCP or the assistant.</li>
        <li>Use the returned aggregate score as a hard gate (&ge; target) before merging or deploying.</li>
        <li>If a regression is detected, <code class="font-mono text-xs">flow_evaluation_results</code> tells you exactly which rows failed and why.</li>
    </ol>

    <x-docs.callout type="tip">
        Combine evaluation with the
        <a href="{{ route('docs.show', 'workflows') }}" class="text-primary-600 hover:underline">Workflow</a>
        versioning system: keep the golden dataset pointed at a frozen workflow version, so you can compare
        a new candidate revision side-by-side with the known-good baseline.
    </x-docs.callout>

    {{-- Related --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Related concepts</h2>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><a href="{{ route('docs.show', 'workflows') }}" class="text-primary-600 hover:underline">Workflows</a> — the object under test for flow evaluations.</li>
        <li><a href="{{ route('docs.show', 'skills') }}" class="text-primary-600 hover:underline">Skills</a> — carry their own built-in guardrail evaluators for per-output quality checks.</li>
        <li><a href="{{ route('docs.show', 'metrics') }}" class="text-primary-600 hover:underline">Metrics &amp; Comparison</a> — aggregate evaluation results across runs and models.</li>
    </ul>
</x-layouts.docs>
