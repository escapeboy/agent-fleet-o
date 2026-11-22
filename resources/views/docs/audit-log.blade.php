<x-layouts.docs
    title="Audit Log"
    description="FleetQ's immutable audit log records every platform action. Learn what is logged, how to query it, and how to use it for compliance."
    page="audit-log"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Audit Log — Full Activity History</h1>
    <p class="mt-4 text-gray-600">
        Every significant action on the platform is recorded in an <strong>append-only audit log</strong>.
        Entries are never modified or deleted within your plan's retention window — giving you a trustworthy
        history for compliance audits, incident investigations, and team accountability.
    </p>

    {{-- What is logged --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">What is logged</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        @foreach([
            ['Experiment transitions', 'Every state change — who triggered it, when, and why.'],
            ['Approval decisions', 'Approve/reject decisions with the reviewer\'s identity.'],
            ['Budget events', 'Reservations, settlements, alerts, and budget exhaustion events.'],
            ['Agent events', 'Health check failures, status changes, and execution starts.'],
            ['Credential access', 'Every decryption of a credential — with the requesting agent and experiment.'],
            ['Team changes', 'Member invitations, role changes, and token creation/revocation.'],
        ] as [$event, $desc])
        <div class="rounded-lg border border-gray-200 p-4">
            <p class="font-semibold text-sm text-gray-900">{{ $event }}</p>
            <p class="mt-1 text-xs text-gray-600">{{ $desc }}</p>
        </div>
        @endforeach
    </div>

    {{-- Accessing --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Accessing the audit log</h2>
    <p class="mt-2 text-sm text-gray-600">
        View in the app at <a href="/audit" class="text-primary-600 hover:underline">/audit</a>,
        or query via API with filtering:
    </p>

    <x-docs.code lang="bash">
# All audit entries
GET {{ url('/api/v1/audit') }}

# Filter by event type and date range
GET {{ url('/api/v1/audit') }}?event=experiment.transitioned&from=2026-01-01&to=2026-01-31

# Filter by actor (user ID)
GET {{ url('/api/v1/audit') }}?actor_id=01jf4a2b-...</x-docs.code>

    {{-- Exporting --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Exporting the audit log</h2>
    <p class="mt-2 text-sm text-gray-600">
        Use the <strong>Export</strong> menu on the audit log page to download your team's entries as a file.
        Two formats are available:
    </p>
    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600">
        <li><strong>CSV</strong>: one row per entry, ready for spreadsheets. Cells that begin with
            <code class="rounded bg-gray-100 px-1 text-xs">=</code>, <code class="rounded bg-gray-100 px-1 text-xs">+</code>,
            <code class="rounded bg-gray-100 px-1 text-xs">-</code> or <code class="rounded bg-gray-100 px-1 text-xs">@</code>
            get a leading apostrophe so they are never run as formulas.</li>
        <li><strong>JSON Lines (OCSF)</strong>: one JSON object per line, shaped as an
            OCSF (Open Cybersecurity Schema Framework) event
            (<code class="rounded bg-gray-100 px-1 text-xs">class_uid</code>, <code class="rounded bg-gray-100 px-1 text-xs">severity_id</code>,
            <code class="rounded bg-gray-100 px-1 text-xs">time</code>, <code class="rounded bg-gray-100 px-1 text-xs">actor</code>, ...).</li>
    </ul>
    <p class="mt-2 text-sm text-gray-600">
        The export uses the filters currently set on the page: search text, event category and the from/to date range.
        Only team owners and admins can export, and every export is itself recorded as an
        <code class="rounded bg-gray-100 px-1 text-xs">audit.exported</code> entry with the format and filters used.
    </p>

    <x-docs.code lang="bash">
# Download the OCSF JSON Lines export (same session as the app)
GET {{ url('/audit/export') }}?format=jsonl&event=experiment&from=2026-01-01&to=2026-01-31

# Ship it to a SIEM: most accept newline-delimited JSON through file or HTTP ingestion
curl -X POST "$SIEM_INGEST_URL" \
  -H "Authorization: Bearer $SIEM_TOKEN" \
  -H "Content-Type: application/x-ndjson" \
  --data-binary @audit-log-2026-01-31-120000.jsonl</x-docs.code>

    <p class="mt-2 text-sm text-gray-600">
        For programmatic access, the <code class="rounded bg-gray-100 px-1 text-xs">/api/v1/audit</code> endpoint
        remains cursor-paginated.
    </p>

    {{-- Streaming --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Streaming to a SIEM</h2>
    <p class="mt-2 text-sm text-gray-600">
        To receive every new audit entry as it happens, create a webhook endpoint subscribed to the
        <code class="rounded bg-gray-100 px-1 text-xs">audit.entry.created</code> event, either on the webhooks
        settings page or through the API. Each delivery carries one entry, as the same OCSF record that the
        JSON Lines export produces.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        This event is opt-in: endpoints subscribed to <code class="rounded bg-gray-100 px-1 text-xs">*</code>
        do not receive it. List <code class="rounded bg-gray-100 px-1 text-xs">audit.entry.created</code> by name.
    </p>

    <x-docs.code lang="bash">
# Create an endpoint that receives the audit stream
POST {{ url('/api/v1/webhooks') }}
{
  "name": "SIEM",
  "url": "YOUR_SIEM_INGEST_URL",
  "events": ["audit.entry.created"],
  "secret": "your-signing-secret"
}

# Each delivery body
{
  "event": "audit.entry.created",
  "timestamp": "2026-01-31T12:00:00+00:00",
  "data": { "class_uid": 3001, "severity_id": 1, "time": 1769860800000, "actor": { ... }, ... }
}</x-docs.code>

    <p class="mt-2 text-sm text-gray-600">
        When the endpoint has a secret, each request carries
        <code class="rounded bg-gray-100 px-1 text-xs">X-Fleetq-Signature: sha256={hex}</code>, an HMAC-SHA256 of the
        raw request body with that secret. Compute the same HMAC on your side and compare before accepting the event.
    </p>

    {{-- Retention --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Retention periods</h2>
    <p class="mt-2 text-sm text-gray-600">
        The <code class="rounded bg-gray-100 px-1 text-xs">audit:cleanup</code> command runs daily at 02:00
        and removes entries older than your plan's retention window:
    </p>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Plan</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Retention</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr><td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Free</td><td class="py-2.5 pr-4 text-sm text-gray-600">7 days</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Starter</td><td class="py-2.5 pr-4 text-sm text-gray-600">30 days</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Pro</td><td class="py-2.5 pr-4 text-sm text-gray-600">90 days</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Enterprise</td><td class="py-2.5 pr-4 text-sm text-gray-600">365 days (custom unlimited available)</td></tr>
            </tbody>
        </table>
    </div>
    <x-docs.callout type="info">
        Enterprise customers can configure unlimited retention. Contact support to enable archival to S3 or GCS.
    </x-docs.callout>

    {{-- Compliance --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Compliance use cases</h2>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li><strong>SOC 2</strong>: Evidence that access to credentials and data is logged and attributable</li>
        <li><strong>GDPR</strong>: Track who accessed data, when, and for what purpose</li>
        <li><strong>Internal audits</strong>: Investigate why an experiment ran, who approved it, and what it cost</li>
        <li><strong>Incident response</strong>: Reconstruct the exact sequence of events around a failure or security incident</li>
    </ul>
</x-layouts.docs>
