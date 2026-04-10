<x-layouts.docs
    title="Integrations"
    description="Connect FleetQ to external services via OAuth2. Integrations power inbound signal connectors for GitHub, Linear, and Jira, and expose capabilities that agents and workflows can act on."
    page="integrations"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Integrations — Connect External Services</h1>
    <p class="mt-4 text-gray-600">
        <strong>Integrations</strong> connect FleetQ to external services via OAuth2. Once connected, agents and
        workflows can interact with these services through their APIs — creating issues, sending messages,
        querying repositories, and more. Integrations also power inbound
        <a href="{{ route('docs.show', 'signals') }}" class="text-primary-600 hover:underline">Signal connectors</a>
        for GitHub, Linear, and Jira.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>An engineering team connects their GitHub and Linear accounts. A workflow
        agent automatically creates a Linear issue when a GitHub PR is merged without a linked ticket, then
        posts a Slack summary to the engineering channel.</em>
    </p>

    {{-- Available integrations --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Available integrations</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Integration</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Capabilities</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">GitHub</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Repository management, issue and pull request operations, webhook subscriptions,
                        workflow run status, releases, and branch management.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Linear</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Issue tracking, project and cycle management, team membership, comment threads,
                        and label operations.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Jira</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Issue tracking, sprint management, project configuration, comment operations,
                        and transition workflows.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Slack</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Messaging, channel management, and user lookups via OAuth2 bot scopes.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Activepieces</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Point FleetQ at a self-hosted Activepieces instance and gain immediate access to
                        <strong>660+ pre-built integrations</strong> — Stripe, Slack, GitHub, Google Sheets,
                        HubSpot, Salesforce, Notion, OpenAI, and many more. See the Activepieces section below.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">WhatsApp</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Verified webhook endpoint for WhatsApp Business Cloud API. Ingests inbound messages as
                        Signals and delivers outbound messages via the <code class="rounded bg-gray-100 px-1">whatsapp</code>
                        outbound connector. GET challenge + POST delivery receipts supported.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Google Drive / Notion / Confluence</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Read-only integrations used primarily to power Chatbot and Knowledge Base indexing.
                        Documents are ingested, chunked, and embedded for retrieval by agents.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">More coming soon</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        New first-party integrations are added regularly. Check the
                        <a href="/integrations" class="text-primary-600 hover:underline">Integrations page</a>
                        for the latest available providers.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Activepieces --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Activepieces — 660+ integrations at once</h2>
    <p class="mt-2 text-sm text-gray-600">
        <a href="https://activepieces.com" class="text-primary-600 hover:underline" target="_blank" rel="noopener">Activepieces</a>
        is an open-source automation platform. Each "piece" it ships with is natively exposed as an MCP server
        endpoint. FleetQ's Activepieces connector lets any team that runs an Activepieces instance auto-import
        <strong>all of them</strong> as <code class="rounded bg-gray-100 px-1">mcp_http</code> Tool records —
        zero per-connector maintenance, maximum integration surface.
    </p>
    <ul class="mt-3 list-disc pl-5 text-sm text-gray-600">
        <li><strong>Hourly sync job</strong> discovers new pieces and updates existing ones.</li>
        <li><strong>Piece filter</strong> — restrict which pieces are imported (e.g. only <code class="font-mono text-xs">stripe</code>, <code class="font-mono text-xs">slack</code>, <code class="font-mono text-xs">notion</code>).</li>
        <li><strong>SSRF guard</strong> — the Activepieces URL is validated on every sync and API call.</li>
        <li><strong>Manual trigger</strong> — run <code class="font-mono text-xs">integration_manage({action: "activepieces_sync"})</code> to refresh on demand.</li>
    </ul>
    <x-docs.callout type="info">
        Imported pieces show up in the regular <a href="{{ route('docs.show', 'tools') }}" class="text-primary-600 hover:underline">Tools</a>
        list and can be attached to agents like any other MCP HTTP tool. When Activepieces updates a piece, the
        sync job updates the corresponding Tool record in place — assignments are preserved.
    </x-docs.callout>

    {{-- Connecting --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Connecting an integration</h2>
    <p class="mt-2 text-sm text-gray-600">
        All integrations use the standard OAuth2 authorization code flow. Tokens are stored encrypted at rest
        using your team's per-team key.
    </p>

    <ol class="mt-4 space-y-2 text-sm text-gray-600">
        <li class="flex gap-2">
            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700">1</span>
            Go to <a href="/integrations" class="text-primary-600 hover:underline">Integrations</a> in the sidebar.
        </li>
        <li class="flex gap-2">
            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700">2</span>
            Click <strong>Connect</strong> next to the provider you want to add.
        </li>
        <li class="flex gap-2">
            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700">3</span>
            Authorize FleetQ in the provider's OAuth screen and grant the requested scopes.
        </li>
        <li class="flex gap-2">
            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700">4</span>
            You are redirected back to FleetQ. The integration status changes to <strong>Connected</strong>.
        </li>
    </ol>

    <x-docs.callout type="tip">
        OAuth credentials are encrypted at rest using your team's per-team encryption key — the same
        mechanism used for all sensitive credentials in FleetQ. You can rotate or revoke access at any
        time from the provider's own OAuth settings page.
    </x-docs.callout>

    {{-- Using integrations --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Using integrations in agents and workflows</h2>
    <p class="mt-2 text-sm text-gray-600">
        Once connected, an integration exposes a set of named capabilities — discrete actions the connected
        account can perform. Agents and workflow nodes can invoke these capabilities without handling OAuth
        tokens directly.
    </p>

    <p class="mt-3 text-sm text-gray-600">
        Use the <code class="rounded bg-gray-100 px-1">integration_capabilities</code> MCP tool to list all
        available actions for a connected integration, then use <code class="rounded bg-gray-100 px-1">integration_execute</code>
        to run an action against the live service.
    </p>

    <x-docs.code lang="json" title="Example: list GitHub capabilities">
// integration_capabilities({ integration_id: "..." })
{
  "capabilities": [
    { "action": "create_issue",    "description": "Create a new issue in a repository" },
    { "action": "list_issues",     "description": "List issues with filters" },
    { "action": "create_pr",       "description": "Open a pull request" },
    { "action": "list_repos",      "description": "List accessible repositories" },
    { "action": "merge_pr",        "description": "Merge a pull request" }
  ]
}</x-docs.code>

    <x-docs.code lang="json" title="Example: execute an action">
// integration_execute({ integration_id: "...", action: "create_issue", params: { ... } })
{
  "result": {
    "id": 42,
    "url": "https://github.com/acme/api/issues/42",
    "title": "Automated: missing Linear ticket for PR #118"
  }
}</x-docs.code>

    {{-- Integration status --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Integration status and health</h2>
    <p class="mt-2 text-sm text-gray-600">
        Ping an integration at any time to verify that the stored token is still valid and the remote service
        is reachable. If a token expires or is revoked at the provider, FleetQ marks the integration as
        <strong>disconnected</strong> and stops routing agent actions through it.
    </p>

    <x-docs.code lang="bash">
# Via API
curl -X POST https://your-fleet.example.com/api/v1/integrations/{id}/ping \
  -H "Authorization: Bearer YOUR_TOKEN"</x-docs.code>

    <x-docs.callout type="info">
        Expiring OAuth tokens trigger an automatic disconnection event. Re-authorize from the
        <a href="/integrations" class="text-primary-600 hover:underline">Integrations page</a> to restore
        connectivity. Some providers (e.g. Linear) issue long-lived refresh tokens — FleetQ silently
        exchanges them before they expire.
    </x-docs.callout>

    {{-- Signal connectors --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Signal connectors</h2>
    <p class="mt-2 text-sm text-gray-600">
        Integrations also power inbound <strong>Signal connectors</strong>. Once you have connected GitHub,
        Linear, or Jira, you can create per-repository or per-team subscriptions that automatically ingest
        events as Signals — issues created, PRs opened, comments added, and more.
    </p>

    <p class="mt-3 text-sm text-gray-600">
        Signal connector setup is covered in the
        <a href="{{ route('docs.show', 'signals') }}" class="text-primary-600 hover:underline">Signals documentation</a>.
        The short version: connect the integration first here, then configure subscriptions under
        <a href="/signals/bindings" class="text-primary-600 hover:underline">Signal Sources → Subscriptions</a>.
    </p>

    <x-docs.callout type="tip">
        Each subscription gets its own unique webhook URL with an opaque UUIDv7 path segment. This provides
        security for providers like Jira that do not sign webhook payloads.
    </x-docs.callout>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        The following MCP tools are available for integration management. Use them via the
        <a href="{{ route('docs.show', 'mcp-server') }}" class="text-primary-600 hover:underline">MCP server</a>
        or the AI Assistant.
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">integration_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all integrations for the team with their connection status.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">integration_connect</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Initiate an OAuth2 connection flow and return the authorization URL.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">integration_disconnect</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Revoke stored tokens and disconnect an integration.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">integration_ping</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Verify that a connected integration's token is valid and the service is reachable.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">integration_execute</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Run a named action against a connected integration with the provided parameters.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">integration_capabilities</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all actions available for a connected integration.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- API endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
    <p class="mt-2 text-sm text-gray-600">
        All integration endpoints are under <code class="rounded bg-gray-100 px-1">/api/v1/integrations</code>
        and require a Sanctum bearer token. Full OpenAPI 3.1 docs are available at
        <a href="/docs/api" class="text-primary-600 hover:underline">/docs/api</a>.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-semibold text-green-700">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/integrations</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all integrations for the team.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-semibold text-green-700">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/integrations/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Get a single integration with full status details.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-semibold text-blue-700">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/integrations/connect</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Initiate an OAuth2 connection — returns the authorization URL.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-semibold text-blue-700">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/integrations/{id}/disconnect</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Disconnect an integration and revoke stored tokens.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-semibold text-blue-700">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/integrations/{id}/ping</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Ping the connected service to verify token validity.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-semibold text-blue-700">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/integrations/{id}/execute</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Execute a named action against the connected service.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-semibold text-green-700">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/integrations/{id}/capabilities</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List available actions for a connected integration.</td>
                </tr>
            </tbody>
        </table>
    </div>
</x-layouts.docs>
