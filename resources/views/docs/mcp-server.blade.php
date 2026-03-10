<x-layouts.docs
    title="MCP Server"
    description="Connect AI agents directly to FleetQ via MCP (Model Context Protocol). 143 tools across 24 domains, available via stdio or HTTP/SSE."
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

    {{-- Tool list --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">143 tools across 24 domains</h2>
    <p class="mt-2 text-sm text-gray-600">Every platform capability is available as an MCP tool:</p>

    <div class="mt-4 grid gap-2 sm:grid-cols-2">
        @foreach([
            ['Agent',       9,  'agent_list, agent_create, agent_update, agent_toggle_status'],
            ['Experiment',  13, 'experiment_list, experiment_create, pause, resume, retry, kill, retry_from_step'],
            ['Crew',        7,  'crew_list, crew_create, crew_execute, execution_status'],
            ['Skill',       9,  'skill_list, skill_create, skill_update, versions'],
            ['Tool',        7,  'tool_list, tool_create, tool_update, tool_delete'],
            ['Credential',  5,  'credential_list, credential_create, credential_rotate'],
            ['Workflow',    11, 'workflow_list, create, validate, generate, estimate_cost'],
            ['Project',     9,  'project_list, project_create, pause, resume, trigger_run'],
            ['Signal',      13, 'signal_list, signal_ingest, connector_binding, intent_score_query'],
            ['Budget',      3,  'budget_summary, budget_check, budget_forecast'],
            ['Marketplace', 6,  'marketplace_browse, install, publish, review'],
            ['System',      4,  'system_dashboard_kpis, system_health, audit_log'],
        ] as [$domain, $count, $examples])
        <div class="rounded-lg border border-gray-200 p-3">
            <div class="flex items-center justify-between">
                <span class="font-medium text-gray-900 text-sm">{{ $domain }}</span>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $count }} tools</span>
            </div>
            <p class="mt-1 text-xs text-gray-500">{{ $examples }}</p>
        </div>
        @endforeach
    </div>

    <x-docs.callout type="tip">
        Local agents (Claude Code, Codex) connected via stdio cost <strong>zero credits</strong> —
        FleetQ's <code class="text-xs">LocalAgentGateway</code> spawns CLI processes directly with no API call.
        Perfect for development and testing.
    </x-docs.callout>
</x-layouts.docs>
