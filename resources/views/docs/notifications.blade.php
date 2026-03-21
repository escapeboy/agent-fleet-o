<x-layouts.docs
    title="Notifications"
    description="FleetQ sends in-app notifications and email alerts for important platform events. Learn about notification types, the notification bell, inbox, preferences, contacts, webhooks, and the MCP tools for notification management."
    page="notifications"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Notifications</h1>
    <p class="mt-4 text-gray-600">
        FleetQ keeps you informed about important platform events through in-app notifications and email alerts.
        Notifications are team-scoped — every team member receives events relevant to their work — and each user
        can independently configure which notification types they want and how they are delivered.
    </p>

    {{-- Notification Types --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Notification types</h2>
    <p class="mt-2 text-sm text-gray-600">
        The table below lists every notification FleetQ can send, when it fires, and its default delivery channels.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Type</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">When it fires</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Default channels</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Experiment transitions</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-600">
                        When an experiment changes state — especially on failures such as
                        <code class="rounded bg-gray-100 px-1">ScoringFailed</code>,
                        <code class="rounded bg-gray-100 px-1">PlanningFailed</code>, or
                        <code class="rounded bg-gray-100 px-1">Killed</code>
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">In-app, Email</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Approval requests</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-600">
                        When a workflow node or experiment stage requires human approval before continuing
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">In-app, Email</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Budget alerts</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-600">
                        When team credit spend reaches 80% or 100% of the configured budget
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">In-app, Email</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Project completions/failures</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-600">
                        When a project run finishes successfully (<code class="rounded bg-gray-100 px-1">completed</code>)
                        or fails (<code class="rounded bg-gray-100 px-1">failed</code>)
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">In-app, Email</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Milestone reached</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-600">
                        When a project milestone is marked as completed
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">In-app</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Usage alerts</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-600">
                        When monthly usage (experiment runs, outbound sends) approaches or exceeds plan limits — triggered at 80% and 100%
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">In-app, Email</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Weekly digest</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-600">
                        A summary of team activity sent every Monday at 09:00 — experiments run, credits used, approvals pending
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Email</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Welcome</td>
                    <td class="py-2.5 pr-6 text-xs text-gray-600">
                        Sent to new users upon first login to guide them through onboarding
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">In-app, Email</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Notification Bell --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Notification bell</h2>
    <p class="mt-2 text-sm text-gray-600">
        The bell icon in the application header shows a live unread count badge. Clicking it opens a
        dropdown with your most recent notifications, each showing its type, a short description, and
        a timestamp. Notifications are marked as read automatically when you click through to the
        referenced entity (experiment, approval, project, etc.).
    </p>

    <x-docs.callout type="tip">
        The unread badge updates in real time via Livewire — no page refresh needed.
        If you have many pending approvals, the bell is the fastest way to jump directly to the approval inbox.
    </x-docs.callout>

    {{-- Notification Inbox --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Notification inbox</h2>
    <p class="mt-2 text-sm text-gray-600">
        The full notification history is available at
        <a href="{{ route('notifications.index') }}" class="text-primary-600 hover:underline">/notifications</a>.
        From the inbox you can:
    </p>
    <ul class="mt-3 space-y-1 pl-5 text-sm text-gray-600 list-disc">
        <li>View all notifications with their full message and timestamp.</li>
        <li>Mark individual notifications as read, or mark all as read at once.</li>
        <li>Filter the list by notification type (e.g., show only budget alerts).</li>
        <li>Click through to the source entity directly from the notification row.</li>
    </ul>

    {{-- Notification Preferences --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Notification preferences</h2>
    <p class="mt-2 text-sm text-gray-600">
        Each user can configure which notifications they receive and how they are delivered at
        <a href="{{ route('notifications.preferences') }}" class="text-primary-600 hover:underline">/notifications/preferences</a>.
        For each notification type you can choose:
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Option</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Behaviour</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">In-app only</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Notifications appear in the bell and inbox, but no email is sent.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Email only</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Notifications are sent by email but do not appear in the in-app inbox.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Both</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Default. Notifications are delivered via both channels.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">None</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Disable this notification type entirely for your account.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="info">
        Preferences are per-user, not per-team. Each team member manages their own delivery settings independently.
        Team owners and admins cannot override individual member preferences.
    </x-docs.callout>

    {{-- Contacts & Channels --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Contacts &amp; channels</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ has a contact system for managing <em>external</em> notification recipients — people or
        entities outside your team that should receive automated outbound messages. Contacts are separate
        from team member notifications and are used primarily by outbound connectors and signal routing.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Model</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">ContactIdentity</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Represents an external person or entity — for example, a lead, customer, or partner.
                        Stores name, type, and optional metadata. Browse contacts at
                        <a href="{{ route('contacts.index') }}" class="text-primary-600 hover:underline">/contacts</a>.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">ContactChannel</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Defines how to reach a contact — a delivery channel such as email address, Telegram
                        chat ID, or Slack user/channel. Each contact can have multiple channels.
                        FleetQ uses the appropriate channel when dispatching outbound messages.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-sm text-gray-600">
        Contacts are resolved at delivery time by
        <code class="rounded bg-gray-100 px-1">ContactResolver</code>, which matches outbound proposals
        to the correct channel for each recipient. When a signal arrives containing a known contact
        identifier, trigger rules can route it directly to that contact's preferred channel.
    </p>

    {{-- MCP Tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        The following MCP tools are available for notification management via the
        <a href="{{ route('docs.show', 'mcp-server') }}" class="text-primary-600 hover:underline">AgentFleetServer</a>:
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What it does</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">notification_manage</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        List notifications (with optional unread filter), mark one or all as read,
                        and read or update per-type notification preferences for the current user.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.code lang="bash">
# List the 10 most recent unread notifications
notification_manage action=list unread=true limit=10

# Mark all notifications as read
notification_manage action=mark_all_read

# Read current notification preferences
notification_manage action=get_preferences

# Disable email for budget alerts
notification_manage action=update_preferences type=budget_alert channel=email enabled=false</x-docs.code>

    {{-- Webhook Notifications --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Webhook notifications</h2>
    <p class="mt-2 text-sm text-gray-600">
        In addition to in-app and email notifications, you can configure <strong>outbound webhook endpoints</strong>
        to receive platform events as HTTP POST payloads. This allows integrating FleetQ event streams with
        external systems such as Slack, PagerDuty, or custom dashboards.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        Webhook endpoints are managed at the team level. Each endpoint has a target URL, optional HMAC
        secret for payload verification, and a list of event types to subscribe to.
    </p>

    <x-docs.callout type="tip">
        FleetQ signs each outbound webhook request with an
        <code class="rounded bg-gray-100 px-1">X-FleetQ-Signature</code> header
        (HMAC-SHA256 of the raw JSON body using your endpoint secret).
        Always verify this signature on your receiving server before processing the payload.
    </x-docs.callout>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Webhook MCP tools</h3>

    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What it does</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">webhook_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all configured webhook endpoints for the team.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">webhook_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new webhook endpoint with a target URL, secret, and event subscriptions.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">webhook_update</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update an existing webhook endpoint — change URL, secret, active status, or subscribed events.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">webhook_delete</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a webhook endpoint and stop all event delivery to that URL.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- API --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API reference</h2>
    <p class="mt-2 text-sm text-gray-600">
        Notification state and webhook configuration are accessible via the REST API.
        Full request/response schemas are available in the
        <a href="/docs/api" class="text-primary-600 hover:underline">OpenAPI 3.1 reference</a>.
    </p>

    <h3 class="mt-5 text-base font-semibold text-gray-900">Notification endpoints</h3>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method + Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">GET /api/v1/notifications</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List notifications for the authenticated user (cursor-paginated).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">POST /api/v1/notifications/{id}/read</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Mark a single notification as read.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">POST /api/v1/notifications/read-all</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Mark all unread notifications as read.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">GET /api/v1/notifications/preferences</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Get the current user's notification preferences.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">PUT /api/v1/notifications/preferences</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update notification preferences for one or more notification types.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Webhook endpoints</h3>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method + Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">GET /api/v1/webhooks</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all webhook endpoints for the team.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">GET /api/v1/webhooks/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Get details for a single webhook endpoint.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">POST /api/v1/webhooks</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new webhook endpoint.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">PUT /api/v1/webhooks/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update a webhook endpoint's configuration.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">DELETE /api/v1/webhooks/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a webhook endpoint.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.code lang="bash">
# Create a webhook endpoint that receives experiment failure events
curl -X POST {{ url('/api/v1/webhooks') }} \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://hooks.example.com/fleetq",
    "secret": "your-webhook-secret",
    "events": ["experiment.failed", "budget.alert", "approval.requested"]
  }'</x-docs.code>
</x-layouts.docs>
