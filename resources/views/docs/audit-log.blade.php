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
