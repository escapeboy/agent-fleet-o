<x-layouts.docs
    title="Triggers"
    description="FleetQ Trigger Rules automatically react to incoming signals. Learn how to define conditions, set actions, and test rules without waiting for live data."
    page="triggers"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Triggers — Automatic Reactions to Signals</h1>
    <p class="mt-4 text-gray-600">
        <strong>Trigger Rules</strong> are if-then automation rules evaluated on every incoming signal.
        When conditions match, FleetQ takes the configured action — launching an experiment, triggering
        a project run, or sending a notification.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>When a signal arrives with a ClearCue intent score above 80,
        FleetQ automatically starts the "High-Intent Outreach" experiment with the company name
        injected into the goal.</em>
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
        @foreach(['equals', 'not_equals', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'regex', 'exists', 'not_exists'] as $op)
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">create_experiment</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Start an experiment with the signal content interpolated into the goal.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">create_project_run</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Trigger an immediate run of a continuous project.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">send_notification</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Send an in-app or email notification to a team member.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">send_webhook</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">POST the signal data to a configured outbound webhook endpoint.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Example --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Example trigger rule</h2>

    <x-docs.code lang="json" title="Create via API">
{
  "name": "High-Intent Auto-Launch",
  "status": "active",
  "conditions": [
    {
      "field": "content.score",
      "operator": "gt",
      "value": 80
    },
    {
      "field": "source",
      "operator": "equals",
      "value": "clearcue"
    }
  ],
  "action": "create_experiment",
  "action_config": {
    "agent_id": "01jf4a2b-...",
    "goal": "Draft personalised outreach email for {{ '{{signal.content.company}}' }}"
  }
}</x-docs.code>

    <x-docs.callout type="tip">
        Use <code class="text-xs">{{ '{{signal.title}}' }}</code>, <code class="text-xs">{{ '{{signal.source}}' }}</code>,
        and <code class="text-xs">{{ '{{signal.content.*}}' }}</code> interpolation in action configs to pass signal data to the experiment goal.
    </x-docs.callout>

    {{-- Testing --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Testing rules without live data</h2>
    <p class="mt-2 text-sm text-gray-600">
        From the Triggers page (<a href="/triggers" class="text-primary-600 hover:underline">/triggers</a>),
        click <strong>Test Rule</strong> on any active rule and provide a sample signal payload.
        FleetQ evaluates the conditions and shows which would fire — without actually executing the action.
    </p>

    <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/triggers/RULE_ID/test') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "signal": {
      "title": "Test lead",
      "source": "clearcue",
      "content": { "company": "Acme Corp", "score": 92 }
    }
  }'</x-docs.code>
</x-layouts.docs>
