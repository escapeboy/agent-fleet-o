<x-layouts.docs
    title="MCP Server"
    description="Connect AI agents directly to FleetQ via MCP (Model Context Protocol). 34 consolidated tools for Claude.ai or 400 granular tools for power users, available via stdio or HTTP/SSE with OAuth 2.0."
    page="mcp-server"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">MCP Server — Connect AI Agents Directly</h1>
    <p class="mt-4 text-gray-600">
        FleetQ exposes a <strong>Model Context Protocol (MCP)</strong> server that gives LLMs and agent frameworks
        direct, tool-based access to every platform capability. No REST calls, no token management in your
        prompts — just natural language.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A developer connects Claude Code to FleetQ via MCP and types:
        "Create an experiment that monitors Hacker News for mentions of our product and summarises the
        top 5 posts every morning." Claude calls the <code>agent_create</code>, <code>skill_create</code>,
        and <code>project_create</code> MCP tools in sequence — the entire setup is done in one conversation.</em>
    </p>

    {{-- Endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Three endpoints, two servers</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ ships a <strong>compact</strong> and a <strong>full</strong> MCP server so clients with tool-count
        limits (Claude.ai) and clients without (Cursor, Claude Code) can both use the platform natively:
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Endpoint</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Transport</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Tools</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Auth</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Best for</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">POST /mcp</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">HTTP / SSE</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">34 consolidated meta-tools</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">OAuth 2.0 or bearer token</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Claude.ai, Claude Desktop, ChatGPT</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">POST /mcp/full</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">HTTP / SSE</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">400 granular tools</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">OAuth 2.0 or bearer token</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Cursor, remote automation, CI pipelines</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">agent-fleet</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">stdio</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">400 granular tools</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Auto (team owner)</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Claude Code, Codex — local dev machines</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-sm text-gray-600">
        Both HTTP servers share the same OAuth discovery endpoints, consent screen, and BYOK provider credentials.
        The compact server consolidates related operations into 34 meta-tools that take an <code class="text-xs">action</code>
        parameter (e.g. <code class="text-xs">agent_manage</code> with action=<code class="text-xs">list</code>),
        delegating internally to the same 400 implementations used by the full server.
    </p>

    {{-- stdio setup --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Setting up stdio (Claude Code)</h2>
    <p class="mt-2 text-sm text-gray-600">
        Add FleetQ to your Claude Code MCP configuration:
    </p>

    <x-docs.code lang="json" title="~/.claude/claude.json">
{
  "mcp_servers": {
    "agent-fleet": {
      "command": "php",
      "args": ["/path/to/agent-fleet/artisan", "mcp:start", "agent-fleet"]
    }
  }
}</x-docs.code>

    <p class="mt-3 text-sm text-gray-600">
        Or start it directly from the terminal:
    </p>
    <x-docs.code lang="bash">
php artisan mcp:start agent-fleet</x-docs.code>

    {{-- HTTP setup --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Setting up HTTP (Cursor, remote)</h2>
    <p class="mt-2 text-sm text-gray-600">
        Create an API token in <a href="/team" class="text-primary-600 hover:underline">Team Settings → API Tokens</a>,
        then configure your MCP client:
    </p>

    <x-docs.code lang="json" title="Cursor MCP config — full server (all 400 tools)">
{
  "mcpServers": {
    "agent-fleet": {
      "url": "{{ url('/mcp/full') }}",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}</x-docs.code>
    <p class="mt-3 text-sm text-gray-600">
        For Claude.ai and other clients with tool-count limits, use <code class="text-xs">{{ url('/mcp') }}</code>
        (compact, 34 meta-tools) instead of <code class="text-xs">/mcp/full</code>.
    </p>

    {{-- OAuth for Claude.ai and other dynamic clients --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Setting up in Claude.ai (OAuth)</h2>
    <p class="mt-2 text-sm text-gray-600">
        Claude.ai, Claude Desktop, and other MCP clients that speak OAuth 2.0 can connect without a
        pre-issued API token. Go to <strong>Settings &rarr; Connectors &rarr; Add custom connector</strong>
        in Claude.ai and paste the server URL:
    </p>

    <x-docs.code lang="text" title="Server URL">{{ url('/mcp') }}</x-docs.code>

    <p class="mt-4 text-sm text-gray-600">
        FleetQ implements the full
        <a href="https://modelcontextprotocol.io/specification/latest/basic/authorization" class="text-primary-600 hover:underline" target="_blank" rel="noopener">MCP authorization spec</a>:
    </p>
    <ul class="mt-2 list-disc pl-6 text-sm text-gray-600 space-y-1">
        <li><strong>Authorization Code flow with PKCE (S256)</strong> — no client secret required for public clients</li>
        <li><strong>Dynamic Client Registration (RFC 7591)</strong> at <code class="text-xs">/oauth/register</code> — no manual client setup</li>
        <li><strong>Authorization Server metadata (RFC 8414)</strong> at <code class="text-xs">/.well-known/oauth-authorization-server</code></li>
        <li><strong>Protected Resource metadata (RFC 9728)</strong> at <code class="text-xs">/.well-known/oauth-protected-resource</code></li>
        <li><strong>Scope:</strong> <code class="text-xs">mcp:use</code> (single scope covering all tool invocations)</li>
        <li><strong>Refresh tokens</strong> via the standard <code class="text-xs">refresh_token</code> grant</li>
    </ul>

    <p class="mt-4 text-sm text-gray-600">
        When Claude.ai connects, you will be redirected to the FleetQ consent screen to authorize access
        for the calling team. Tokens are scoped to the team you choose at consent time — no cross-tenant
        access is possible.
    </p>

    {{-- Tool Profiles --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Tool profiles (compact server only)</h2>
    <p class="mt-2 text-sm text-gray-600">
        On the compact server (<code class="text-xs">/mcp</code>), teams can further restrict which meta-tools are
        visible via profiles in <strong>Team Settings &rarr; MCP Tools</strong>. The full server
        (<code class="text-xs">/mcp/full</code>) always exposes all 400 tools.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Profile</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Meta-tools</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Best for</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Essential</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">11 meta-tools</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Simple agents with focused tasks</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Standard</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">22 meta-tools (core + operations)</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Most use cases</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Full</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">All 34 meta-tools</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Power users and automation</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Custom</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Hand-picked</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Teams with specific needs</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Compact meta-tool catalog (what Claude.ai actually sees on /mcp) --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">34 meta-tools on <code class="text-lg">/mcp</code> (compact server)</h2>
    <p class="mt-2 text-sm text-gray-600">
        This is what Claude.ai, Claude Desktop, and other tool-limited clients see. Each meta-tool takes an
        <code class="text-xs">action</code> parameter plus action-specific arguments, and delegates internally to
        the corresponding granular tool on the full server:
    </p>

    <div class="mt-4 grid gap-2 sm:grid-cols-2">
        @foreach([
            ['agent_manage',       'Agents: list, get, create, update, delete, toggle_status, set_reasoning_strategy, templates, constraint_templates, runtime_state'],
            ['agent_advanced',     'Agents: config_history, rollback, skill_sync, tool_sync, feedback (list, stats, submit), hooks, heartbeat, workspace export/import'],
            ['project_manage',     'Projects: list, get, create, update, activate, pause, resume, restart, archive, trigger_run, runs_list, milestones'],
            ['workflow_manage',    'Workflows: list, get, create, update, delete, validate, generate, activate, duplicate, estimate_cost, execution_chain'],
            ['workflow_graph',     'Edit graph DAG: save_graph, node_add/update/delete, edge_add/delete'],
            ['experiment_manage',  'Experiments: list, get, create, start, pause, resume, retry, kill, retry_from_step, cost, share, steps'],
            ['crew_manage',        'Crews: list, get, create, update, execute, execution_status, executions_list, messages, propose_restructuring'],
            ['budget_manage',      'Budget: summary, check, forecast, alerts'],
            ['memory_manage',      'Memory: search, list_recent, stats, add, delete, propose, promote, upload_knowledge, unified_search'],
            ['system_manage',      'System: dashboard_kpis, health, version_check, audit_log, global_settings, langfuse_config'],
            ['credential_manage',  'Credentials: list, get, create, update, delete, rotate, rollback, list_versions, oauth_initiate, oauth_finalize'],
            ['trigger_manage',     'Trigger rules: list, create, update, delete, test'],
            ['skill_manage',       'Skills: list, get, create, update, delete, versions, annotate, benchmark, playground_test, generate_improvement'],
            ['tool_manage',        'Tools: list, get, create, update, delete, activate, deactivate, probe_remote_mcp, import_mcp, bash_policy, ssh_fingerprints, middleware_config'],
            ['approval_manage',    'Approvals: list, get, approve, reject, complete_human_task, escalate, webhook_config, security_reviews'],
            ['signal_manage',      'Signals: list, get, ingest, contacts (manage, risk, high_risk, reevaluate), email_reply, imap_mailbox'],
            ['signal_connectors',  'Connectors: Slack, Telegram, Alert, ClearCue, HTTP monitor, Ticket, Supabase, SearxNG, IntentScore, KG add/search/entity_facts, inbound binding + subscription'],
            ['knowledge_manage',   'Knowledge: list, get, create, update, delete, upload, sync_now, search'],
            ['artifact_manage',    'Artifacts: list, get, content, download_info'],
            ['outbound_manage',    'Outbound: connector_config list/save/test/delete, ntfy_send'],
            ['webhook_manage',     'Webhooks: list, get, create, update, delete'],
            ['team_manage',        'Team: get, update, members, BYOK credentials, API tokens, custom endpoints, local_llm, notifications, push_subscriptions, plugins, mcp_tool_preferences'],
            ['integration_manage', 'Integrations: list, get, connect, disconnect, ping, capabilities, activepieces_sync'],
            ['integration_execute','Integration tool execution (separate from integration_manage for clarity)'],
            ['marketplace_manage', 'Marketplace: browse, get, publish, install, review, categories, analytics'],
            ['email_manage',       'Email: templates list/create/update/delete/generate; themes list/create/update/delete'],
            ['chatbot_manage',     'Chatbots: list, get, create, update, delete, toggle_status, tokens, sessions, analytics, learning_entries'],
            ['bridge_manage',      'Bridge: status, endpoint_list, endpoint_toggle, connect, rename, update_url, set_routing, disconnect'],
            ['assistant_manage',   'AI assistant: conversation list/get/create/clear, send_message, annotate, review, compact'],
            ['git_manage',         'Git repos: list/get/create/update/delete/test, branch_create, commit, file_write, pull_request create/close/merge/status, release, changelog, version_bump, workflow_dispatch'],
            ['profile_manage',     'User: profile get/update, password change, 2FA, social accounts, sessions, notification preferences'],
            ['evolution_manage',   'Evolution proposals: list, get, analyze, approve, apply, reject'],
            ['boruna_manage',      'Boruna QA: run tests, validate, evidence collection, capabilities list'],
            ['admin_manage',       'Super admin: team_suspend, billing_apply_credit, billing_refund, team_billing_detail, security_overview, user_revoke_sessions, user_send_password_reset'],
        ] as [$name, $description])
        <div class="rounded-lg border border-gray-200 p-3">
            <code class="font-mono text-xs font-semibold text-primary-700">{{ $name }}</code>
            <p class="mt-1 text-xs text-gray-500">{{ $description }}</p>
        </div>
        @endforeach
    </div>

    <p class="mt-6 text-sm text-gray-600">
        Need direct access to each granular operation? Connect to <code class="text-xs">/mcp/full</code> instead —
        it exposes 400 individual tools (e.g. <code class="text-xs">agent_list</code>, <code class="text-xs">agent_create</code>,
        <code class="text-xs">agent_update</code> as separate entries in tools/list). Both servers share the same
        OAuth flow, BYOK credentials, and ApprovalsResource MCP App widget.
    </p>

    <x-docs.callout type="tip">
        Local agents (Claude Code, Codex) connected via stdio cost <strong>zero credits</strong> —
        FleetQ's <code class="text-xs">LocalAgentGateway</code> spawns CLI processes directly with no API call.
        Perfect for development and testing.
    </x-docs.callout>

    <h2 class="mt-10 text-xl font-bold text-gray-900">Troubleshooting</h2>
    <p class="mt-2 text-sm text-gray-600">
        Common issues and fixes when connecting the FleetQ MCP server:
    </p>
    <div class="mt-4 space-y-4 text-sm text-gray-600">
        <div>
            <p class="font-semibold text-gray-900">401 <code class="text-xs">Unauthorized — bearer token required</code></p>
            <p class="mt-1">
                Your client did not send a bearer token, or the token has expired. In Claude.ai, disconnect and reconnect
                the connector to trigger a fresh OAuth flow. For manual Sanctum tokens, create a new one in
                <a href="/team" class="text-primary-600 hover:underline">Team Settings &rarr; API Tokens</a> and update your client config.
            </p>
        </div>
        <div>
            <p class="font-semibold text-gray-900">OAuth redirect URI mismatch</p>
            <p class="mt-1">
                FleetQ uses <a href="https://datatracker.ietf.org/doc/html/rfc7591" class="text-primary-600 hover:underline" target="_blank" rel="noopener">Dynamic Client Registration (RFC 7591)</a>, so
                redirect URIs are registered automatically. If you see a redirect mismatch error, your client is
                probably using a stale cached client_id. Clear the connector from Claude.ai and re-add it.
            </p>
        </div>
        <div>
            <p class="font-semibold text-gray-900">Tool call returns <code class="text-xs">insufficient_budget</code></p>
            <p class="mt-1">
                The team's credit reservation check failed. Check
                <a href="/billing" class="text-primary-600 hover:underline">Billing</a> for the current balance, or
                upgrade to Pro to remove the monthly usage cap. Tool calls that use BYOK provider keys are not
                billed against the FleetQ budget — make sure your team has at least one active provider credential
                in <a href="/team" class="text-primary-600 hover:underline">Team Settings &rarr; Provider Credentials</a>.
            </p>
        </div>
        <div>
            <p class="font-semibold text-gray-900">Tool call times out after 300s</p>
            <p class="mt-1">
                Claude.ai enforces a 5-minute timeout per tool call. Long-running experiments, workflows, and crew
                executions run asynchronously — use the <code class="text-xs">experiment_start</code> or
                <code class="text-xs">workflow_execute</code> tools which return a run ID immediately, then poll
                <code class="text-xs">experiment_get</code> / <code class="text-xs">workflow_run_get</code> for status.
            </p>
        </div>
        <div>
            <p class="font-semibold text-gray-900">429 rate limit</p>
            <p class="mt-1">
                FleetQ enforces 200 tool calls per minute per team. If you hit this limit, slow down batched calls
                or upgrade to Enterprise for a custom rate. The <code class="text-xs">Retry-After</code> header tells
                you when to retry.
            </p>
        </div>
        <div>
            <p class="font-semibold text-gray-900">tools/list only returns 34 tools, not 400</p>
            <p class="mt-1">
                You are connected to the compact server at <code class="text-xs">/mcp</code>, which consolidates
                the 400 granular tools into 34 meta-tools. To get each operation as a separate tool, point your
                client to <code class="text-xs">/mcp/full</code> instead. Both endpoints share the same OAuth flow
                and credentials.
            </p>
        </div>
        <div>
            <p class="font-semibold text-gray-900">tools/list returns fewer meta-tools than expected (compact server)</p>
            <p class="mt-1">
                Per-team tool filtering is active on <code class="text-xs">/mcp</code>. Check
                <a href="/team" class="text-primary-600 hover:underline">Team Settings &rarr; MCP Tool Preferences</a>
                for the active profile — switch to <strong>Full</strong> to expose all 34 meta-tools, or build a
                <strong>Custom</strong> profile that includes just what you need.
            </p>
        </div>
        <div>
            <p class="font-semibold text-gray-900">Approvals inbox widget does not render</p>
            <p class="mt-1">
                The <code class="text-xs">ui://fleetq/approvals</code> MCP App resource only appears in clients that
                declare the MCP Apps capability during the initialize handshake (currently Claude.ai and Claude Desktop
                on recent builds). Claude Code does not yet render MCP Apps.
            </p>
        </div>
    </div>
    <p class="mt-4 text-sm text-gray-600">
        Still stuck? Email <a href="mailto:support@fleetq.net" class="text-primary-600 hover:underline">support@fleetq.net</a>
        with your team ID (visible in <a href="/team" class="text-primary-600 hover:underline">Team Settings</a>) and a
        description of what you were trying to do. We respond within 2 business hours during review.
    </p>

    <h2 class="mt-10 text-xl font-bold text-gray-900">Privacy, terms & support</h2>
    <p class="mt-2 text-sm text-gray-600">
        Use of the FleetQ MCP server is governed by our public policies:
    </p>
    <ul class="mt-2 list-disc pl-6 text-sm text-gray-600 space-y-1">
        <li><a href="{{ route('legal.privacy') }}" class="text-primary-600 hover:underline">Privacy Policy</a> — GDPR + CCPA compliant, covers data collection, retention, third-party sharing, and your rights.</li>
        <li><a href="{{ route('legal.terms') }}" class="text-primary-600 hover:underline">Terms of Service</a> — acceptable use, liability, and service level.</li>
        <li><strong>Support:</strong> <a href="mailto:support@fleetq.net" class="text-primary-600 hover:underline">support@fleetq.net</a> for connector issues, onboarding questions, or enterprise enquiries.</li>
    </ul>
</x-layouts.docs>
