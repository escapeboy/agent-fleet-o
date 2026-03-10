<x-layouts.docs
    title="Experiments"
    description="FleetQ experiments are AI pipeline runs with a 22-state engine. Learn how to create, monitor, pause, retry, and kill experiments."
    page="experiments"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Experiments — The AI Pipeline Engine</h1>
    <p class="mt-4 text-gray-600">
        An <strong>Experiment</strong> is a single run of an AI workflow. It moves through up to 22 states —
        from <em>Draft</em> all the way to <em>Completed</em> — with automatic checkpointing, budget enforcement,
        human approval gates, and a full audit trail at every step.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A SaaS company uses experiments to detect churn signals every week.
        The experiment pulls usage data, scores accounts by risk, drafts personalised win-back emails,
        and waits for a human to approve before sending.</em>
    </p>

    {{-- Pipeline states --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">The 22-state pipeline</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every experiment progresses through an explicit state machine. Transitions are validated before execution —
        you can never skip states or create inconsistent data.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">State</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What happens</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-xs">
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Draft</td><td class="py-2.5 pr-4 text-gray-600">Created, not yet submitted for processing.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">SignalDetected</td><td class="py-2.5 pr-4 text-gray-600">Created automatically from an inbound signal via a Trigger Rule.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Scoring</td><td class="py-2.5 pr-4 text-gray-600">AI evaluates the goal and assigns a feasibility score.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Planning</td><td class="py-2.5 pr-4 text-gray-600">Breaks the goal into a step-by-step execution plan.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Building</td><td class="py-2.5 pr-4 text-gray-600">Constructs the execution environment and validates resources.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">AwaitingApproval</td><td class="py-2.5 pr-4 text-gray-600">Paused for human review before execution.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Approved / Rejected</td><td class="py-2.5 pr-4 text-gray-600">Human decision recorded. Rejected experiments loop back to Planning.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Executing</td><td class="py-2.5 pr-4 text-gray-600">Agent is actively running the plan. Live logs available.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">AwaitingChildren</td><td class="py-2.5 pr-4 text-gray-600">Waiting for parallel workflow branches (dynamic_fork nodes) to complete.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">ExecutionFailed</td><td class="py-2.5 pr-4 text-gray-600">A stage failed during execution. Retryable from any checkpoint step.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">CollectingMetrics</td><td class="py-2.5 pr-4 text-gray-600">Output gathered, token costs settled.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Evaluating</td><td class="py-2.5 pr-4 text-gray-600">Quality check — did the output meet the goal?</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Iterating</td><td class="py-2.5 pr-4 text-gray-600">Auto-revision loop when output quality is insufficient.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Completed</td><td class="py-2.5 pr-4 text-gray-600 font-medium">Terminal — success.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Paused</td><td class="py-2.5 pr-4 text-gray-600">Temporarily suspended. Can be resumed.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">Killed</td><td class="py-2.5 pr-4 text-gray-600 font-medium">Terminal — manually terminated.</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono font-medium text-gray-900">*Failed states</td><td class="py-2.5 pr-4 text-gray-600">ScoringFailed, PlanningFailed, BuildingFailed — each retryable.</td></tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="warning">
        Terminal states (<code>Completed</code>, <code>Killed</code>, <code>Discarded</code>, <code>Expired</code>) are irreversible.
        Use <strong>Pause</strong> if you want to hold an experiment temporarily.
    </x-docs.callout>

    {{-- Creating --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Creating an experiment</h2>
    <p class="mt-2 text-sm text-gray-600">Create from the UI at <a href="/experiments" class="text-primary-600 hover:underline">/experiments</a>, or via API:</p>

    <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/experiments') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Churn Risk Analysis — Week 12",
    "agent_id": "01jf4a2b-...",
    "goal": "Analyse usage data and identify top 10 churn-risk accounts",
    "budget_cents": 5000
  }'</x-docs.code>

    {{-- Monitoring --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Monitoring in real time</h2>
    <p class="mt-2 text-sm text-gray-600">
        The experiment detail page shows:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li><strong>Timeline</strong> — state transitions with timestamps and actor</li>
        <li><strong>Execution Log</strong> — live streaming output from the agent</li>
        <li><strong>Stage progress</strong> — which playbook steps have completed</li>
        <li><strong>Cost tracker</strong> — credits reserved and settled in real time</li>
        <li><strong>Artifacts</strong> — versioned outputs you can preview or download</li>
    </ul>

    {{-- Retry from step --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Retrying from a specific step</h2>
    <p class="mt-2 text-sm text-gray-600">
        Instead of rerunning the entire experiment, you can retry from any step. Only that step and its
        downstream dependencies are reset — completed steps are preserved.
    </p>

    <x-docs.code lang="bash">
# Retry from step "generate_email" onwards
curl -X POST {{ url('/api/v1/experiments/EXPERIMENT_ID/retry-from-step') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"step_id": "STEP_ID"}'</x-docs.code>

    {{-- Tracks --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Experiment tracks</h2>
    <p class="mt-2 text-sm text-gray-600">
        Tracks classify what kind of business outcome an experiment targets. This enables filtering, reporting,
        and metric attribution by business goal:
    </p>
    <div class="mt-3 grid gap-2 sm:grid-cols-3">
        @foreach([
            ['growth',      'Acquisition, activation, and new revenue experiments.'],
            ['retention',   'Churn prevention, win-back, and engagement experiments.'],
            ['revenue',     'Upsell, expansion, and monetisation experiments.'],
            ['engagement',  'Product usage, content, and community experiments.'],
            ['debug',       'Internal diagnostics, testing, and platform experiments.'],
        ] as [$track, $desc])
        <div class="rounded-lg border border-gray-200 p-3">
            <p class="font-mono text-xs font-semibold text-gray-900">{{ $track }}</p>
            <p class="mt-1 text-xs text-gray-600">{{ $desc }}</p>
        </div>
        @endforeach
    </div>

    {{-- Artifacts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Artifacts</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every experiment produces one or more <strong>Artifacts</strong> — versioned output files (documents, code,
        data, or media). You can:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li>Preview them inline at <code class="rounded bg-gray-100 px-1 text-xs">/artifacts/{id}/render</code></li>
        <li>Download via <code class="rounded bg-gray-100 px-1 text-xs">GET /api/v1/artifacts/{id}/download</code></li>
        <li>Pipe them into outbound connectors (email, Slack, webhook)</li>
    </ul>
</x-layouts.docs>
