<x-layouts.docs
    title="MCP Server"
    description="Connect AI agents directly to FleetQ via MCP (Model Context Protocol). 268+ tools across 37 domains, available via stdio or HTTP/SSE."
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

    {{-- Transports --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Two transports</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Transport</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Path / Command</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Auth</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Best for</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">HTTP / SSE</td>
                    <td class="py-3 pr-6 font-mono text-xs text-gray-600">POST /mcp</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Sanctum bearer token</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Cursor, remote agent frameworks, CI pipelines</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">stdio</td>
                    <td class="py-3 pr-6 font-mono text-xs text-gray-600">agent-fleet</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Auto (team owner)</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Claude Code, Codex — local dev machines</td>
                </tr>
            </tbody>
        </table>
    </div>

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

    <x-docs.code lang="json" title="Cursor MCP config">
{
  "mcpServers": {
    "agent-fleet": {
      "url": "{{ url('/mcp') }}",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}</x-docs.code>

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

    {{-- Compact vs Full --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Compact vs Full server</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ offers two HTTP endpoints for different client capabilities:
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Endpoint</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Tools</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Best for</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">POST /mcp</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">~33 meta-tools (consolidated via <code>action</code> parameter)</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Claude.ai, ChatGPT — clients with tool count limits</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">POST /mcp/full</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">All 268+ tools individually</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Cursor, Claude Code remote, power users</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-sm text-gray-600">
        The compact server consolidates related tools into meta-tools. For example, <code class="rounded bg-gray-100 px-1 text-xs">agent_manage</code>
        supports actions: <code class="text-xs">list</code>, <code class="text-xs">get</code>, <code class="text-xs">create</code>,
        <code class="text-xs">update</code>, <code class="text-xs">toggle_status</code>, <code class="text-xs">delete</code>, etc.
    </p>

    {{-- Tool Profiles --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Tool profiles</h2>
    <p class="mt-2 text-sm text-gray-600">
        Teams can customize which tools are available via profiles in <strong>Team Settings → MCP Tools</strong>:
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Profile</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Tools</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Best for</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Essential</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">11 core tools</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Simple agents with focused tasks</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Standard</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">33 tools (core + operations)</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Most use cases</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Full</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">All 268+ tools</td>
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

    {{-- Tool list --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">268+ tools across 37 domains</h2>
    <p class="mt-2 text-sm text-gray-600">Every platform capability is available as an MCP tool:</p>

    <div class="mt-4 grid gap-2 sm:grid-cols-2">
        @foreach([
            ['Agent',       'agent_list, agent_create, agent_update, agent_toggle_status, agent_delete, agent_rollback, agent_templates_list'],
            ['Experiment',  'experiment_list, experiment_create, pause, resume, retry, kill, retry_from_step, experiment_share'],
            ['Crew',        'crew_list, crew_create, crew_execute, execution_status, crew_executions_list'],
            ['Skill',       'skill_list, skill_create, skill_update, versions, guardrail, multi_model_consensus'],
            ['Tool',        'tool_list, tool_create, tool_update, tool_delete, tool_activate, tool_discover_mcp, tool_bash_policy'],
            ['Credential',  'credential_list, credential_create, credential_rotate, oauth_initiate, oauth_finalize'],
            ['Workflow',    'workflow_list, create, validate, generate, activate, duplicate, estimate_cost, execution_chain'],
            ['Project',     'project_list, project_create, pause, resume, trigger_run, project_archive'],
            ['Approval',    'approval_list, approval_approve, approval_reject, complete_human_task, webhook_config'],
            ['Signal',      'signal_list, signal_ingest, connector_binding, contact_manage, imap_mailbox, intent_score, kg_search'],
            ['Outbound',    'connector_config_list, connector_config_save, connector_config_test'],
            ['Budget',      'budget_summary, budget_check, budget_forecast'],
            ['Marketplace', 'marketplace_browse, install, publish, review, categories, analytics'],
            ['Memory',      'memory_search, memory_list_recent, memory_stats, memory_delete, memory_upload_knowledge'],
            ['Artifact',    'artifact_list, artifact_get, artifact_content, artifact_download_info'],
            ['Webhook',     'webhook_list, webhook_create, webhook_update, webhook_delete'],
            ['Trigger',     'trigger_rule_list, create, update, delete, trigger_rule_test'],
            ['Integration', 'integration_list, integration_connect, integration_disconnect, integration_ping, integration_execute'],
            ['Assistant',   'assistant_conversation_list, assistant_conversation_get, assistant_send_message, assistant_conversation_clear'],
            ['Email',       'email_template_list, email_template_create, email_template_generate, email_theme_list'],
            ['Chatbot',     'chatbot_list, chatbot_create, chatbot_update, chatbot_toggle_status, chatbot_analytics_summary'],
            ['Bridge',      'bridge_status, bridge_endpoint_list, bridge_endpoint_toggle, bridge_disconnect'],
            ['Telegram',    'telegram_bot_manage'],
            ['Shared',      'notification_manage, team_get, team_update, local_llm, team_byok_credential_manage, api_token_manage'],
            ['Cache',       'semantic_cache_stats, semantic_cache_purge'],
            ['Evolution',   'evolution_proposal_list, evolution_analyze, evolution_apply, evolution_reject'],
            ['System',      'system_dashboard_kpis, system_health, system_version_check, system_audit_log'],
            ['Compute',     'compute_manage'],
            ['RunPod',      'runpod_manage'],
            ['Git',         'git_status, git_log, git_diff, git_branches, git_commit, git_push, git_pull, git_stash'],
        ] as [$domain, $examples])
        <div class="rounded-lg border border-gray-200 p-3">
            <span class="font-medium text-gray-900 text-sm">{{ $domain }}</span>
            <p class="mt-1 text-xs text-gray-500">{{ $examples }}</p>
        </div>
        @endforeach
    </div>

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
            <p class="font-semibold text-gray-900">tools/list response is missing tools</p>
            <p class="mt-1">
                Per-team tool filtering is active — check
                <a href="/team" class="text-primary-600 hover:underline">Team Settings &rarr; MCP Tool Preferences</a>
                for the active profile. Set to <code class="text-xs">full</code> to expose all 400 tools.
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
