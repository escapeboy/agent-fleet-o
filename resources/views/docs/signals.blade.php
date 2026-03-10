<x-layouts.docs
    title="Signals"
    description="FleetQ Signals are inbound events from the world. Learn about webhook connectors, RSS polling, ClearCue intent data, and signal stacking."
    page="signals"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Signals — Inbound Data from the World</h1>
    <p class="mt-4 text-gray-600">
        A <strong>Signal</strong> is any inbound event: a webhook payload from your CRM, an RSS article,
        a ClearCue intent event, or a manual entry. Signals are the raw material that triggers automation —
        connect them to experiments and projects via <a href="{{ route('docs.show', 'triggers') }}" class="text-primary-600 hover:underline">Trigger Rules</a>.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A B2B sales team receives signals from three sources:
        ClearCue (buyer intent data), an RSS feed (industry news), and a Salesforce webhook (new lead created).
        High-intent signals automatically trigger the "Enterprise Outreach" experiment.</em>
    </p>

    {{-- Connector types --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Signal sources</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Connector</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">How it works</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Webhook</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        POST to <code class="rounded bg-gray-100 px-1">/api/signals/webhook</code> with an HMAC-SHA256 signature.
                        Any system that can send an HTTP request can send a signal.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">RSS</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Provide a feed URL. FleetQ polls it every 15 minutes and creates one signal per new item.
                        Duplicate detection prevents the same item from being ingested twice.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Manual</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Create signals directly via the API (<code class="rounded bg-gray-100 px-1">POST /api/v1/signals</code>)
                        or from the <a href="/signals/entities" class="text-primary-600 hover:underline">Entity Browser</a> in the app.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">ClearCue</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Native integration with ClearCue's intent data platform.
                        HMAC-verified webhook maps company/person data into a signal with a FIRE model composite score.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Webhook example --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Sending a webhook signal</h2>
    <p class="mt-2 text-sm text-gray-600">
        Configure a webhook secret in FleetQ (<a href="/signals/bindings" class="text-primary-600 hover:underline">Signal Sources → Bindings</a>),
        then sign your requests:
    </p>

    <x-docs.code lang="bash">
# Generate signature: HMAC-SHA256 of raw request body, using your webhook secret
SIGNATURE=$(echo -n '{"title":"New Enterprise Lead","source":"salesforce","content":{"company":"Acme Corp","score":92}}' | openssl dgst -sha256 -hmac "YOUR_SECRET" -binary | base64)

curl -X POST {{ url('/api/signals/webhook') }} \
  -H "X-Signature: sha256=${SIGNATURE}" \
  -H "Content-Type: application/json" \
  -d '{"title":"New Enterprise Lead","source":"salesforce","content":{"company":"Acme Corp","score":92}}'</x-docs.code>

    <x-docs.callout type="tip">
        Store your webhook secret in FleetQ's <strong>Credentials</strong> vault. It's encrypted at rest with a per-team key.
    </x-docs.callout>

    {{-- FIRE model --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Signal stacking — FIRE model score</h2>
    <p class="mt-2 text-sm text-gray-600">
        When ClearCue signals arrive, FleetQ calculates a <strong>composite intent score</strong> using the FIRE model.
        Repeated signals from the same company stack — each additional signal increases the score with a stacking bonus.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Factor</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Weight</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What it measures</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Fit</td>
                    <td class="py-2.5 pr-6 text-gray-600">25%</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">How well the company matches your ICP</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Intent</td>
                    <td class="py-2.5 pr-6 text-gray-600">40%</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Buying signals — content consumed, searches, demo requests</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Engagement</td>
                    <td class="py-2.5 pr-6 text-gray-600">25%</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Interaction with your brand (emails opened, site visits)</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Relationship</td>
                    <td class="py-2.5 pr-6 text-gray-600">10%</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Existing connections (warm intro, past customer)</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-gray-500">
        Score tags: <strong class="text-red-600">Hot</strong> (≥80), <strong class="text-orange-600">Warm</strong> (60–79),
        <strong class="text-yellow-600">Lukewarm</strong> (40–59), <strong class="text-gray-500">Cold</strong> (&lt;40).
        Exponential decay (λ=0.05) reduces scores for inactive companies over time.
    </p>
</x-layouts.docs>
