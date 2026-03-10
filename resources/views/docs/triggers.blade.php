<x-layouts.docs
    title="Triggers"
    description="FleetQ Trigger Rules automatically react to incoming signals. Learn how to define conditions, set actions, and test rules without waiting for live data."
    page="triggers"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Triggers — Automatic Reactions to Signals</h1>
    <p class="mt-4 text-gray-600">
        <strong>Trigger Rules</strong> are if-then automation rules evaluated on every incoming signal.
        When conditions match, FleetQ triggers a project run automatically — no manual intervention needed.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>When a signal arrives with a ClearCue intent score above 80,
        FleetQ automatically triggers the "High-Intent Outreach" project run, passing the signal
        data as context.</em>
    </p>

    {{-- How it works --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">How it works</h2>
    <p class="mt-2 text-sm text-gray-600">
        After every signal is ingested, <code class="rounded bg-gray-100 px-1 text-xs">EvaluateTriggerRulesJob</code>
        runs asynchronously. It evaluates all active trigger rules for your team against the signal's content.
        Matching rules fire their actions — in parallel if multiple rules match.
    </p>

    {{-- Condition operators --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Condition operators</h2>
    <div class="mt-3 grid gap-2 sm:grid-cols-3">
        @foreach(['eq', 'neq', 'gte', 'lte', 'contains', 'not_contains', 'exists'] as $op)
        <div class="rounded-lg border border-gray-200 px-3 py-2 text-center font-mono text-xs text-gray-700">{{ $op }}</div>
        @endforeach
    </div>
    <p class="mt-2 text-xs text-gray-500">
        Use dot notation to reference nested signal content: <code class="rounded bg-gray-100 px-1">content.score</code>, <code class="rounded bg-gray-100 px-1">content.company.size</code>.
    </p>

    {{-- Available actions --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Available actions</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Action</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What happens</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">create_project_run</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Trigger an immediate run of a continuous project, passing the signal as context.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Example --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Example trigger rule</h2>

    <x-docs.code lang="json" title="Create via API">
{
  "name": "High-Intent Auto-Run",
  "status": "active",
  "conditions": [
    {
      "field": "content.score",
      "operator": "gte",
      "value": 80
    },
    {
      "field": "source",
      "operator": "eq",
      "value": "clearcue"
    }
  ],
  "action": "create_project_run",
  "action_config": {
    "project_id": "01jf4a2b-..."
  }
}</x-docs.code>

    <x-docs.callout type="tip">
        Use <code class="text-xs">@{{signal.title}}</code>, <code class="text-xs">@{{signal.source}}</code>,
        and <code class="text-xs">@{{signal.content.*}}</code> interpolation in action configs to pass signal data to the experiment goal.
    </x-docs.callout>

    {{-- Testing --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Testing rules without live data</h2>
    <p class="mt-2 text-sm text-gray-600">
        From the Triggers page (<a href="/triggers" class="text-primary-600 hover:underline">/triggers</a>),
        click <strong>Test Rule</strong> on any active rule and provide a sample signal payload.
        FleetQ evaluates the conditions and shows which would fire — without actually executing the action.
    </p>
    <x-docs.callout type="info">
        Rule testing is also available via the MCP tool <code class="text-xs">trigger_rule_test</code> —
        useful for automated validation pipelines and agent-driven workflows.
    </x-docs.callout>
</x-layouts.docs>
