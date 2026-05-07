<x-layouts.docs
    title="Webhooks"
    description="Subscribe to platform events — experiment completed, project run finished — via HTTP push. Get notified when your agents finish work."
    page="webhooks"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Webhooks</h1>
    <p class="mt-4 text-gray-600">
        <strong>Webhooks</strong> let you subscribe to FleetQ platform events and receive an HTTP POST
        to your endpoint when they occur. Use them to trigger downstream systems — update a CRM when
        a lead-research experiment completes, post to Slack when a project run fails, or kick off a
        CI pipeline when an agent finishes a code-review workflow.
    </p>

    <x-docs.callout type="info">
        Webhooks are <em>outbound</em> — FleetQ pushes events to your URL.
        For <em>inbound</em> data (receiving events from external systems), see
        <a href="{{ route('docs.show', 'signals') }}" class="text-primary-600 hover:underline">Signals</a>.
    </x-docs.callout>

    {{-- Core concepts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Core concepts</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Webhook endpoint</p>
            <p class="mt-1 text-sm text-gray-600">
                A URL you own that accepts <code class="font-mono text-xs">POST</code> requests.
                Each endpoint is team-scoped and stores the target URL, an optional secret for
                HMAC-SHA256 signature verification, and a list of subscribed event types.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Event types</p>
            <p class="mt-1 text-sm text-gray-600">
                Subscribe to specific transitions — e.g.
                <code class="font-mono text-xs">experiment.completed</code>,
                <code class="font-mono text-xs">experiment.killed</code>,
                <code class="font-mono text-xs">project_run.completed</code>,
                <code class="font-mono text-xs">approval.requested</code>.
                Only events matching your subscription fire the webhook.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Payload</p>
            <p class="mt-1 text-sm text-gray-600">
                FleetQ sends a JSON payload containing the event type, timestamp, team ID,
                and the full resource object (experiment, project run, etc.) at the time of
                the event. The <code class="font-mono text-xs">X-FleetQ-Signature</code> header
                carries the HMAC-SHA256 signature if a secret is configured.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Retry behaviour</p>
            <p class="mt-1 text-sm text-gray-600">
                Failed deliveries (non-2xx or timeout) are retried up to 3 times with exponential
                backoff. Delivery attempts and response codes are logged for inspection.
            </p>
        </div>
    </div>

    {{-- Verifying signatures --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Verifying signatures</h2>
    <p class="mt-2 text-sm text-gray-600">
        If you configure a webhook secret, verify each delivery in your receiver:
    </p>
    <x-docs.code lang="php" title="Signature verification (PHP)">
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_FLEETQ_SIGNATURE'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit;
}</x-docs.code>

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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">webhook_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all webhook endpoints for the team.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">webhook_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new webhook endpoint with URL, secret, and event subscriptions.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">webhook_update</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update URL, secret, or subscribed event types on an existing webhook.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">webhook_delete</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a webhook endpoint and stop all future deliveries.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- REST API --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">REST API</h2>
    <p class="mt-2 text-sm text-gray-600">
        Manage webhooks via the REST API at <code class="font-mono text-xs">/api/v1/webhook-endpoints</code>:
        <code class="font-mono text-xs">GET</code>, <code class="font-mono text-xs">POST</code>,
        <code class="font-mono text-xs">PUT {id}</code>, <code class="font-mono text-xs">DELETE {id}</code>.
        Full schema in the <a href="{{ route('docs.show', 'api-reference') }}" class="text-primary-600 hover:underline">API Reference</a>.
    </p>

    {{-- Related --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Related concepts</h2>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><a href="{{ route('docs.show', 'signals') }}" class="text-primary-600 hover:underline">Signals</a> — inbound webhook connector for receiving events from external systems.</li>
        <li><a href="{{ route('docs.show', 'outbound') }}" class="text-primary-600 hover:underline">Outbound Delivery</a> — multi-channel delivery (email, Slack, Telegram) with rate limiting.</li>
        <li><a href="{{ route('docs.show', 'triggers') }}" class="text-primary-600 hover:underline">Triggers</a> — event-driven rules that launch projects when conditions are met.</li>
        <li><a href="{{ route('docs.show', 'experiments') }}" class="text-primary-600 hover:underline">Experiments</a> — the primary source of <code class="font-mono text-xs">experiment.*</code> webhook events.</li>
    </ul>
</x-layouts.docs>
