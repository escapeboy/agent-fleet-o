<x-layouts.docs
    title="Outbound Delivery"
    description="FleetQ Outbound Delivery sends content from experiments and workflows to external channels — email, Telegram, Slack, webhooks, and more. Learn about connectors, rate limiting, blacklists, and delivery tracking."
    page="outbound"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Outbound Delivery</h1>
    <p class="mt-4 text-gray-600">
        <strong>Outbound Delivery</strong> is the mechanism by which FleetQ sends content to the outside world.
        When an agent or workflow produces a result intended for a person or system, it creates an
        <strong>OutboundProposal</strong> — a pending delivery record that travels through approval, rate-limiting,
        and blacklist checks before being dispatched via the configured connector.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A sales automation experiment analyses a new enterprise lead and drafts a
        personalised outreach email. The proposal waits for a sales manager to approve it in the
        <a href="{{ route('approvals.index') }}" class="text-primary-600 hover:underline">Approval Inbox</a>.
        Once approved, the email is sent via the team's custom SMTP connector. Open and click events are
        tracked automatically and fed back into the experiment's metrics.</em>
    </p>

    {{-- Channels --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Supported channels</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Channel</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Email</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Send via the platform's shared SMTP relay or your own SMTP connector.
                        Supports HTML and plain-text bodies, attachments, reply-to headers, and
                        open/click tracking.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Telegram</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Send messages to Telegram chats or channels via a registered bot token and
                        <code class="rounded bg-gray-100 px-1">chat_id</code>. Supports Markdown
                        formatting and inline buttons.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Slack</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Post to Slack channels via an incoming webhook URL or an OAuth bot token.
                        Supports Block Kit message formatting.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Webhook</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        HTTP POST the proposal payload to any URL. Configure custom headers and an
                        auth method (bearer token, basic auth, HMAC signature, or none).
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Custom</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Implement the <code class="rounded bg-gray-100 px-1">OutboundConnectorInterface</code>
                        to add any delivery channel. Register the connector in a service provider and it
                        becomes available for selection in the UI.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Outbound Flow --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Delivery flow</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every outbound message follows the same lifecycle regardless of channel:
    </p>

    <ol class="mt-4 space-y-3 text-sm text-gray-600">
        <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700">1</span>
            <span>
                <strong class="text-gray-900">Proposal created</strong> — the agent or workflow step
                generates an <code class="rounded bg-gray-100 px-1">OutboundProposal</code> with status
                <code class="rounded bg-gray-100 px-1">pending</code>. The proposal contains the rendered
                content, the target recipient, and the connector to use.
            </span>
        </li>
        <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700">2</span>
            <span>
                <strong class="text-gray-900">Approval gate</strong> — if the experiment or project
                requires human approval, the proposal waits in the
                <a href="{{ route('approvals.index') }}" class="text-primary-600 hover:underline">Approval Inbox</a>
                until a team member approves or rejects it. If auto-approve is enabled the proposal
                advances immediately.
            </span>
        </li>
        <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700">3</span>
            <span>
                <strong class="text-gray-900">Blacklist &amp; rate-limit checks</strong> — before
                delivery, <code class="rounded bg-gray-100 px-1">CheckBlacklist</code> and the
                channel/target rate-limit middleware run. Blocked or throttled proposals are
                marked <code class="rounded bg-gray-100 px-1">blocked</code> or
                <code class="rounded bg-gray-100 px-1">rate_limited</code>.
            </span>
        </li>
        <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700">4</span>
            <span>
                <strong class="text-gray-900">Delivery</strong> — <code class="rounded bg-gray-100 px-1">SendOutbound</code>
                dispatches the proposal via the connector and creates an
                <code class="rounded bg-gray-100 px-1">OutboundAction</code> recording the delivery
                attempt, timestamp, and status (<code class="rounded bg-gray-100 px-1">sent</code>,
                <code class="rounded bg-gray-100 px-1">failed</code>, or
                <code class="rounded bg-gray-100 px-1">bounced</code>).
            </span>
        </li>
    </ol>

    {{-- Connector Configuration --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Connector configuration</h2>
    <p class="mt-2 text-sm text-gray-600">
        Connectors are configured per team at
        <a href="/outbound-connectors" class="text-primary-600 hover:underline">Settings → Outbound Connectors</a>
        or via the API. Each connector stores its credentials encrypted using the team's per-team key.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Connector</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Required configuration</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Email (platform SMTP)</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        From address only — uses the platform's shared relay.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Email (custom SMTP)</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        SMTP host, port, username, password, encryption (tls/ssl/none), from address.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Telegram</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Bot token (from <code class="rounded bg-gray-100 px-1">@BotFather</code>),
                        default <code class="rounded bg-gray-100 px-1">chat_id</code> (can be overridden per proposal).
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Slack</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Incoming webhook URL <em>or</em> OAuth bot token with a default channel name.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Webhook</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Target URL, HTTP method (POST/PUT), custom headers (JSON object),
                        auth method (none / bearer / basic / hmac-sha256).
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        Test any connector before putting it into production with
        <code class="rounded bg-gray-100 px-1">POST /api/v1/outbound-connectors/{id}/test</code>
        or the <strong>Test</strong> button in the UI. A synthetic payload will be delivered
        and the result returned in the response.
    </x-docs.callout>

    {{-- Rate Limiting --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Rate limiting</h2>
    <p class="mt-2 text-sm text-gray-600">
        Two independent rate-limit layers protect against accidental flooding:
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Layer</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Middleware</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What it limits</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Channel rate limit</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">ChannelRateLimit</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Total deliveries per channel type per time window — e.g. max 100 emails/hour
                        across all recipients. Configurable per connector.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Target rate limit</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">TargetRateLimit</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Deliveries to a specific recipient address per time window — e.g. max 5 emails/day
                        to a single email address. Prevents spamming individual contacts.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-sm text-gray-600">
        When a limit is hit, the proposal is marked <code class="rounded bg-gray-100 px-1">rate_limited</code>
        and automatically retried after the window resets. Limits are stored in Redis and reset atomically.
    </p>

    {{-- Blacklist --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Blacklist</h2>
    <p class="mt-2 text-sm text-gray-600">
        The <code class="rounded bg-gray-100 px-1">CheckBlacklist</code> action runs before every delivery
        and compares the recipient against the team's blocked list. Matched proposals are immediately
        marked <code class="rounded bg-gray-100 px-1">blocked</code> — no delivery is attempted and no
        retry is scheduled.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        Manage blocked recipients at <strong>Settings → Outbound → Blacklist</strong>. Entries can be
        individual addresses, domains (e.g. <code class="rounded bg-gray-100 px-1">@example.com</code>),
        or phone numbers.
    </p>

    <x-docs.callout type="warning">
        Proposals blocked by the blacklist are <strong>not retried</strong>. If you unblock a recipient
        after blocking, any already-blocked proposals must be re-queued manually or a new proposal must
        be generated.
    </x-docs.callout>

    {{-- Tracking --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Email tracking</h2>
    <p class="mt-2 text-sm text-gray-600">
        Email opens and link clicks are tracked automatically when using the Email connector.
        FleetQ injects a 1×1 tracking pixel and rewrites links before delivery. Events are
        recorded against the <code class="rounded bg-gray-100 px-1">OutboundAction</code> and available
        in experiment metrics.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Event</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Endpoint</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Behaviour</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Open</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">GET /api/track/pixel</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Returns a transparent 1×1 GIF. Records the open event with timestamp and
                        approximate geo/user-agent data.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Click</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">GET /api/track/click</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Records the click event, then issues a 302 redirect to the original URL.
                        The recipient's browser lands on the intended destination without delay.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="info">
        Tracking is opt-in per connector. Disable it by unchecking <strong>Enable tracking</strong>
        when configuring an Email connector. When disabled, pixel and click-wrapping are skipped
        entirely.
    </x-docs.callout>

    {{-- MCP Tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        All outbound connector management is exposed as MCP tools, allowing AI agents to configure
        and test delivery channels autonomously.
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">connector_config_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all outbound connectors configured for the team.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">connector_config_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve the full configuration for a specific connector by ID.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">connector_config_save</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create or update a connector. Accepts channel type and credential fields.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">connector_config_delete</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a connector by ID. Fails if active proposals reference it.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">connector_config_test</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Send a test payload through the connector and return the delivery result.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- API Endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
    <p class="mt-2 text-sm text-gray-600">
        All endpoints require a Sanctum bearer token.
        Full OpenAPI documentation is available at
        <a href="/docs/api" class="text-primary-600 hover:underline">/docs/api</a>.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-green-700">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">/api/v1/outbound-connectors</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all connectors (cursor-paginated).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-green-700">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">/api/v1/outbound-connectors/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve a single connector.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-blue-700">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">/api/v1/outbound-connectors</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new connector.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-yellow-700">PUT</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">/api/v1/outbound-connectors/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update connector configuration.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-red-700">DELETE</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">/api/v1/outbound-connectors/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a connector.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-blue-700">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-600">/api/v1/outbound-connectors/{id}/test</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Send a test message and return the delivery result.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.code lang="bash">
# Create a Slack connector
curl -X POST {{ url('/api/v1/outbound-connectors') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Sales Slack",
    "channel": "slack",
    "config": {
      "webhook_url": "https://hooks.slack.com/services/T000/B000/xxxx",
      "default_channel": "#sales-alerts"
    }
  }'

# Test it
curl -X POST {{ url('/api/v1/outbound-connectors/{id}/test') }} \
  -H "Authorization: Bearer YOUR_TOKEN"</x-docs.code>
</x-layouts.docs>
