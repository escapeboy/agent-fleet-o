<x-layouts.docs
    title="Tools"
    description="Tools give FleetQ agents access to external capabilities — MCP servers, shell execution, file system access, and browser automation."
    page="tools"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Tools — External Capabilities for Agents</h1>
    <p class="mt-4 text-gray-600">
        A <strong>Tool</strong> extends an agent's reach beyond pure language generation. Tools let agents run shell
        commands, read and write files, control a browser, or call any MCP-compatible server — locally or over HTTP.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A "Web Researcher" agent is given a Playwright MCP tool and a Filesystem tool.
        It navigates to URLs, extracts content, and saves summaries to disk — all without writing a single line of
        integration code.</em>
    </p>

    {{-- Tool types --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Tool types</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Type</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">mcp_stdio</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Local MCP server spawned as a child process and communicated with via stdio.
                        Typical examples: Playwright, filesystem access, git tooling.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">mcp_http</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Remote MCP server accessed via HTTP/SSE. The server runs independently and FleetQ connects
                        to it at a configured URL. Supports custom headers for authentication.
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">built_in</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Platform-provided tools that require no external server. Three kinds available:
                        <span class="font-mono">bash</span> (shell execution),
                        <span class="font-mono">filesystem</span> (read/write files),
                        <span class="font-mono">browser</span> (web automation via Playwright).
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Creating a tool --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Creating a tool</h2>
    <p class="mt-2 text-sm text-gray-600">
        Navigate to <strong>Tools → New Tool</strong> in the UI, or use the API. The form fields depend on the tool type:
    </p>

    <div class="mt-4 space-y-3">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Common fields</p>
            <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
                <li><strong>Name</strong> — unique identifier within your team</li>
                <li><strong>Description</strong> — what the tool does; shown to the LLM when selecting tools</li>
                <li><strong>Type</strong> — <span class="font-mono text-xs">mcp_stdio</span>, <span class="font-mono text-xs">mcp_http</span>, or <span class="font-mono text-xs">built_in</span></li>
                <li><strong>Status</strong> — <span class="font-mono text-xs">active</span> or <span class="font-mono text-xs">disabled</span></li>
            </ul>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Transport config — stdio</p>
            <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
                <li><strong>Command</strong> — executable to spawn (e.g. <span class="font-mono text-xs">npx</span>)</li>
                <li><strong>Args</strong> — argument list (e.g. <span class="font-mono text-xs">["@playwright/mcp"]</span>)</li>
                <li><strong>Env</strong> — optional environment variables injected into the process</li>
            </ul>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Transport config — HTTP</p>
            <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
                <li><strong>URL</strong> — full base URL of the remote MCP server</li>
                <li><strong>Headers</strong> — key-value pairs sent with every request (e.g. <span class="font-mono text-xs">Authorization</span>)</li>
            </ul>
        </div>
    </div>

    <p class="mt-4 text-sm text-gray-600">To create a tool via API:</p>
    <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/tools') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "playwright-mcp",
    "description": "Browser automation via Playwright",
    "type": "mcp_stdio",
    "transport_config": {
      "command": "npx",
      "args": ["@playwright/mcp"],
      "env": {}
    },
    "status": "active"
  }'</x-docs.code>

    {{-- Assigning tools to agents --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Assigning tools to agents</h2>
    <p class="mt-2 text-sm text-gray-600">
        Open an agent's detail page and use the <strong>Tools</strong> tab to attach tools. Each agent can have
        multiple tools with a priority ordering — lower numbers are preferred when the LLM must choose. Tools are
        stored in the <span class="font-mono text-xs">agent_tool</span> pivot table and resolved at execution time
        by <span class="font-mono text-xs">ResolveAgentToolsAction</span>.
    </p>

    <x-docs.callout type="tip">
        Projects can restrict which tools an agent may use via <strong>allowed_tool_ids</strong>. Any tool not in
        that list is silently excluded for runs triggered by that project, even if it's attached to the agent.
    </x-docs.callout>

    {{-- MCP server discovery --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP server discovery</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ can auto-discover MCP servers running on the host machine or reachable via the network. Two MCP tools
        power this workflow:
    </p>
    <ul class="mt-3 list-disc pl-5 text-sm text-gray-600">
        <li>
            <span class="font-mono text-xs font-medium text-gray-900">tool_discover_mcp</span> — scans for available
            MCP servers and returns a list with their capabilities.
        </li>
        <li>
            <span class="font-mono text-xs font-medium text-gray-900">tool_import_mcp</span> — imports a discovered
            server as a new Tool record, ready to assign to agents.
        </li>
    </ul>

    {{-- Built-in tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Built-in tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        Built-in tools are provided by the platform and require no external server or configuration beyond enabling them.
    </p>

    <div class="mt-4 space-y-4">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Bash</p>
            <p class="mt-1 text-sm text-gray-600">
                Executes shell commands. The <strong>sandbox mode</strong> determines <em>where</em> commands run
                (set via <code class="font-mono text-xs">AGENT_BASH_SANDBOX_MODE</code> in <code class="font-mono text-xs">.env</code>), and the <strong>execution policy</strong>
                determines <em>which</em> commands are permitted.
            </p>
            <p class="mt-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Sandbox modes</p>
            <div class="mt-2 overflow-hidden rounded-lg border border-gray-200">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50">
                            <th class="py-2 pl-3 pr-6 text-left font-semibold text-gray-700">Mode</th>
                            <th class="py-2 pr-3 text-left font-semibold text-gray-700">Behaviour</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr>
                            <td class="py-2 pl-3 pr-6 font-mono text-xs font-medium text-gray-900">php</td>
                            <td class="py-2 pr-3 text-xs text-gray-600">Commands run in-process with <code class="rounded bg-gray-100 px-1">CommandSecurityPolicy</code> allowlist. Default for development.</td>
                        </tr>
                        <tr>
                            <td class="py-2 pl-3 pr-6 font-mono text-xs font-medium text-gray-900">docker</td>
                            <td class="py-2 pr-3 text-xs text-gray-600">Each command runs in a Docker container with <code class="rounded bg-gray-100 px-1">--network none</code>, <code class="rounded bg-gray-100 px-1">--read-only</code>, and a mounted workspace.</td>
                        </tr>
                        <tr>
                            <td class="py-2 pl-3 pr-6 font-mono text-xs font-medium text-gray-900">just_bash</td>
                            <td class="py-2 pr-3 text-xs text-gray-600">Commands run inside a persistent <code class="rounded bg-gray-100 px-1">just-bash</code> Node.js sidecar container. Recommended for cloud/production.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Execution policies</p>
            <p class="mt-1 text-sm text-gray-600">Policies are orthogonal to sandbox mode — they apply an allowlist/denylist to whatever is allowed through the sandbox.</p>
            <div class="mt-3 overflow-hidden rounded-lg border border-gray-200">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50">
                            <th class="py-2 pl-3 pr-6 text-left font-semibold text-gray-700">Policy</th>
                            <th class="py-2 pr-3 text-left font-semibold text-gray-700">Behaviour</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr>
                            <td class="py-2 pl-3 pr-6 font-mono text-xs font-medium text-gray-900">allow_all</td>
                            <td class="py-2 pr-3 text-xs text-gray-600">Any command is permitted. Use only in trusted, isolated environments.</td>
                        </tr>
                        <tr>
                            <td class="py-2 pl-3 pr-6 font-mono text-xs font-medium text-gray-900">allowlist</td>
                            <td class="py-2 pr-3 text-xs text-gray-600">Only explicitly listed commands may run.</td>
                        </tr>
                        <tr>
                            <td class="py-2 pl-3 pr-6 font-mono text-xs font-medium text-gray-900">denylist</td>
                            <td class="py-2 pr-3 text-xs text-gray-600">All commands permitted except those explicitly blocked.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">Configure the policy via the <span class="font-mono">tool_bash_policy</span> MCP tool or from the tool detail page.</p>
        </div>

        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Filesystem</p>
            <p class="mt-1 text-sm text-gray-600">
                Reads and writes files within a set of allowed paths. Configure the permitted root directories in the
                tool's transport config. Attempts to access paths outside the allowlist are rejected.
            </p>
        </div>

        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Browser</p>
            <p class="mt-1 text-sm text-gray-600">
                Web automation powered by Playwright / patchright. Agents can navigate pages, click elements,
                fill forms, take screenshots, and extract content. The <strong>browser sandbox mode</strong>
                (<code class="font-mono text-xs">AGENT_BROWSER_SANDBOX_MODE</code>) controls <em>how</em> the browser runs:
            </p>
            <div class="mt-2 overflow-hidden rounded-lg border border-gray-200">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50">
                            <th class="py-2 pl-3 pr-6 text-left font-semibold text-gray-700">Mode</th>
                            <th class="py-2 pr-3 text-left font-semibold text-gray-700">When to use it</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr>
                            <td class="py-2 pl-3 pr-6 font-mono text-xs font-medium text-gray-900">disabled</td>
                            <td class="py-2 pr-3 text-xs text-gray-600">Browser tool returns a plan-upgrade prompt. Default — safest.</td>
                        </tr>
                        <tr>
                            <td class="py-2 pl-3 pr-6 font-mono text-xs font-medium text-gray-900">cloud</td>
                            <td class="py-2 pr-3 text-xs text-gray-600">Tasks delegated to the cloud browser sidecar (headless Chromium, fast, cheap).</td>
                        </tr>
                        <tr>
                            <td class="py-2 pl-3 pr-6 font-mono text-xs font-medium text-gray-900">headful (Xvfb)</td>
                            <td class="py-2 pr-3 text-xs text-gray-600">Runs a real Chromium + patchright inside an Xvfb virtual display. Combine with a <a href="{{ route('docs.show', 'credentials') }}" class="text-primary-600 hover:underline">proxy credential</a> for Reddit/Cloudflare/anti-bot scenarios. Set <code class="rounded bg-gray-100 px-1">headless="false"</code> on the tool.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                FleetQ's system prompt auto-injects a policy that tells agents when to reach for headful mode
                (sites with heavy bot detection) versus cloud mode (fast plain scraping).
            </p>
        </div>
    </div>

    <x-docs.callout type="warning">
        The Bash and Browser tools grant significant system access. Always scope permissions carefully and prefer
        <strong>allowlist</strong> policy for the Bash tool in production environments.
    </x-docs.callout>

    {{-- SSH fingerprints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">SSH fingerprints</h2>
    <p class="mt-2 text-sm text-gray-600">
        For tools that connect to remote servers over SSH, FleetQ supports fingerprint verification to prevent
        man-in-the-middle attacks. Retrieve known fingerprints via the <span class="font-mono text-xs">tool_ssh_fingerprints</span>
        MCP tool or the <span class="font-mono text-xs">GET /api/v1/tools/ssh-fingerprints</span> endpoint.
        Fingerprints are checked automatically when the tool initiates a connection.
    </p>

    {{-- Semantic tool selection --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Semantic tool selection (<code class="text-lg">tool_search</code>)</h2>
    <p class="mt-2 text-sm text-gray-600">
        When a team has more than 15 active tools, loading <em>all</em> of them into every agent prompt wastes
        tokens and dilutes the LLM's focus. FleetQ solves this with <strong>semantic tool selection</strong>:
        tool descriptions are embedded with pgvector and the most relevant tools for the current task are
        retrieved per call.
    </p>
    <ul class="mt-3 list-disc pl-5 text-sm text-gray-600">
        <li>Embeddings live in the <code class="font-mono text-xs">tool_registry_entries</code> table (cosine distance, HNSW index).</li>
        <li>The <code class="font-mono text-xs">tool_search</code> MCP tool can be called directly by agents for explicit tool discovery.</li>
        <li>Retrieval threshold is <code class="font-mono text-xs">&ge; 0.75</code> cosine similarity; tools below that floor are excluded from the agent's toolbelt for that turn.</li>
    </ul>

    {{-- Activepieces auto-sync --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Activepieces auto-sync (660+ integrations)</h2>
    <p class="mt-2 text-sm text-gray-600">
        Point FleetQ at a self-hosted <a href="https://activepieces.com" class="text-primary-600 hover:underline" target="_blank" rel="noopener">Activepieces</a>
        instance and every one of its 660+ "pieces" (Stripe, Slack, GitHub, HubSpot, Salesforce, Notion,
        OpenAI, Google Sheets, …) becomes available in FleetQ automatically as <code class="font-mono text-xs">mcp_http</code> Tool records.
    </p>
    <ul class="mt-3 list-disc pl-5 text-sm text-gray-600">
        <li>Hourly sync job discovers new pieces and updates existing ones.</li>
        <li>Optional <code class="font-mono text-xs">piece_filter</code> limits which pieces are imported.</li>
        <li>SSRF-protected — the Activepieces URL is validated on every request.</li>
        <li>Trigger a manual refresh with the <code class="font-mono text-xs">activepieces_sync</code> action on the <code class="font-mono text-xs">integration_manage</code> MCP meta-tool.</li>
    </ul>

    {{-- Popular tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Popular tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ ships with <strong>16 popular tools pre-seeded</strong> covering common integrations (Playwright,
        filesystem, git, databases, Slack, and more). All are disabled by default to avoid unintended access.
        Enable the ones you need from the <strong>Tools</strong> page — no configuration required for most of them.
    </p>

    <x-docs.callout type="tip">
        Tools that require API keys have placeholder values in their transport config. Fill them in before enabling.
    </x-docs.callout>

    {{-- MCP tools for tool management --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools for tool management</h2>
    <p class="mt-2 text-sm text-gray-600">
        The FleetQ MCP server exposes the following tools so agents and LLMs can manage tools programmatically:
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">MCP tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all tools with optional filtering by type or status.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve full details for a specific tool by ID.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new tool with name, type, and transport config.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_update</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update an existing tool's name, description, or transport config.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_delete</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Soft-delete a tool. Agents that reference it will lose access.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_activate</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Set a tool's status to active, making it available for agent use.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_deactivate</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Disable a tool without deleting it. Existing agent assignments are preserved.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_discover_mcp</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Auto-discover available MCP servers on the host or network.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_import_mcp</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Import a discovered MCP server as a new Tool record.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_ssh_fingerprints</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve SSH fingerprints for tools that connect to remote servers.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">tool_bash_policy</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Get or set the execution policy (allow_all / allowlist / denylist) for the Bash built-in tool.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- API endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/tools</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all tools (cursor paginated).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/tools</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new tool.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/tools/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve a tool by ID.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">PUT</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/tools/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update a tool.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">DELETE</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/tools/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Soft-delete a tool.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/tools/ssh-fingerprints</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve SSH host fingerprints for remote tool connections.</td>
                </tr>
            </tbody>
        </table>
    </div>
</x-layouts.docs>
