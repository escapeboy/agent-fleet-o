<div class="space-y-6">
    {{-- Import MCP Servers --}}
    <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
        <h3 class="text-base font-semibold text-(--color-on-surface)">Import MCP Servers</h3>
        <p class="mt-1 text-sm text-(--color-on-surface-muted)">
            Import MCP server configurations from your IDE or paste JSON directly.
            Discovered servers will be added to your Tools inventory.
        </p>

        {{-- Error message --}}
        @if(session()->has('mcp-error'))
            <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                {{ session('mcp-error') }}
            </div>
        @endif

        {{-- Import result --}}
        @if($mcpImportResult)
            <div class="mt-4 rounded-lg bg-green-50 p-4">
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-circle-check mt-0.5 text-lg flex-shrink-0 text-green-500"></i>
                    <div>
                        <p class="text-sm font-medium text-green-800">
                            Imported {{ $mcpImportResult['imported'] }} server(s),
                            skipped {{ $mcpImportResult['skipped'] }}.
                            @if($mcpImportResult['failed'] > 0)
                                <span class="text-red-700">{{ $mcpImportResult['failed'] }} failed.</span>
                            @endif
                        </p>
                        @if($mcpImportResult['has_credentials'])
                            <p class="mt-1 text-xs text-amber-700">
                                {{ $mcpImportResult['credential_count'] }} server(s) have imported credentials.
                                Review them in <a href="{{ route('tools.index') }}" class="underline">Tools</a>.
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Tabs --}}
        <div x-data="{ tab: 'paste' }" class="mt-6">
            <div class="flex gap-1 rounded-lg bg-(--color-surface-alt) p-1">
                <button @click="tab = 'paste'"
                    :class="tab === 'paste' ? 'bg-(--color-surface-raised) shadow-sm text-(--color-on-surface)' : 'text-(--color-on-surface-muted) hover:text-(--color-on-surface)'"
                    class="flex-1 rounded-md px-3 py-2 text-sm font-medium transition">
                    Paste JSON
                </button>
                <button @click="tab = 'upload'"
                    :class="tab === 'upload' ? 'bg-(--color-surface-raised) shadow-sm text-(--color-on-surface)' : 'text-(--color-on-surface-muted) hover:text-(--color-on-surface)'"
                    class="flex-1 rounded-md px-3 py-2 text-sm font-medium transition">
                    Upload File
                </button>
                @selfhosted
                <button @click="tab = 'scan'"
                    :class="tab === 'scan' ? 'bg-(--color-surface-raised) shadow-sm text-(--color-on-surface)' : 'text-(--color-on-surface-muted) hover:text-(--color-on-surface)'"
                    class="flex-1 rounded-md px-3 py-2 text-sm font-medium transition">
                    Scan Host
                </button>
                @endselfhosted
            </div>

            {{-- Paste JSON Tab --}}
            <div x-show="tab === 'paste'" x-cloak class="mt-4">
                <p class="mb-3 text-xs text-(--color-on-surface-muted)">
                    Paste your MCP configuration JSON. Accepts full config files (with <code class="rounded bg-(--color-surface-alt) px-1">mcpServers</code> key) or a direct server object.
                </p>
                <x-form-textarea wire:model="mcpJsonInput" label="" mono rows="8"
                    placeholder='{"mcpServers": {"server-name": {"command": "npx", "args": ["-y", "package"]}}}' />
                <button wire:click="parseMcpJson"
                    class="mt-3 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700">
                    Parse & Preview
                </button>
            </div>

            {{-- Upload File Tab --}}
            <div x-show="tab === 'upload'" x-cloak class="mt-4">
                <p class="mb-3 text-xs text-(--color-on-surface-muted)">
                    Upload a <code class="rounded bg-(--color-surface-alt) px-1">.json</code> config file from your IDE
                    (e.g., <code class="rounded bg-(--color-surface-alt) px-1">mcp.json</code>, <code class="rounded bg-(--color-surface-alt) px-1">claude_desktop_config.json</code>).
                    Max 1MB.
                </p>
                <input type="file" wire:model="mcpUploadFile" accept=".json,.txt"
                    class="block w-full text-sm text-(--color-on-surface-muted) file:mr-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100" />
                @error('mcpUploadFile')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <button wire:click="parseMcpUpload" wire:loading.attr="disabled"
                    class="mt-3 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="parseMcpUpload">Parse & Preview</span>
                    <span wire:loading wire:target="parseMcpUpload">Processing...</span>
                </button>
            </div>

            @selfhosted
            {{-- Scan Host Tab (self-hosted only) --}}
            <div x-show="tab === 'scan'" x-cloak class="mt-4">
                <p class="mb-3 text-xs text-(--color-on-surface-muted)">
                    Scan this machine for MCP servers configured in Claude Desktop, Claude Code, Cursor, Windsurf, Kiro, and VS Code.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button wire:click="scanHostMcpServers" wire:loading.attr="disabled"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="scanHostMcpServers">Scan for MCP Servers</span>
                        <span wire:loading wire:target="scanHostMcpServers">Scanning...</span>
                    </button>
                </div>
                <p class="mt-2 text-xs text-(--color-on-surface-muted)">
                    Supported: Claude Desktop, Claude Code, Cursor, Windsurf, Kiro, VS Code
                </p>
            </div>
            @endselfhosted
        </div>

        {{-- Discovered Servers Preview --}}
        @if(count($discoveredServers) > 0)
            <div class="mt-6 border-t border-(--color-theme-border) pt-6">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-medium text-(--color-on-surface)">
                        Discovered {{ count($discoveredServers) }} Server(s)
                    </h4>
                    <button wire:click="clearMcpDiscovery" class="text-xs text-(--color-on-surface-muted) hover:text-(--color-on-surface)">
                        Clear
                    </button>
                </div>

                <div class="mt-3 space-y-2">
                    @foreach($discoveredServers as $index => $server)
                        <label class="flex items-start gap-3 rounded-lg border border-(--color-theme-border) p-3 transition hover:border-(--color-theme-border-strong)">
                            <input type="checkbox" wire:model="selectedServers" value="{{ $index }}"
                                class="mt-1 rounded border-(--color-theme-border-strong) text-primary-600 focus:ring-primary-500" />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-(--color-on-surface)">{{ $server['name'] }}</span>
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $server['type'] === 'mcp_stdio' ? 'bg-indigo-100 text-indigo-800' : 'bg-blue-100 text-blue-800' }}">
                                        {{ $server['type'] === 'mcp_stdio' ? 'stdio' : 'HTTP' }}
                                    </span>
                                    @if($server['disabled'])
                                        <span class="inline-flex rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">Disabled</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-xs text-(--color-on-surface-muted)">
                                    Source: {{ $server['source'] }}
                                    @if($server['type'] === 'mcp_stdio' && isset($server['transport_config']['command']))
                                        &middot; <code class="rounded bg-(--color-surface-alt) px-1">{{ $server['transport_config']['command'] }}</code>
                                    @elseif($server['type'] === 'mcp_http' && isset($server['transport_config']['url']))
                                        &middot; {{ $server['transport_config']['url'] }}
                                    @endif
                                </p>
                                @if(!empty($server['warnings']))
                                    @foreach($server['warnings'] as $warning)
                                        <p class="mt-1 text-xs text-amber-600">{{ $warning }}</p>
                                    @endforeach
                                @endif
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button wire:click="importSelectedServers" wire:loading.attr="disabled"
                        @if(empty($selectedServers)) disabled @endif
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="importSelectedServers">
                            Import {{ count($selectedServers) }} Selected
                        </span>
                        <span wire:loading wire:target="importSelectedServers">Importing...</span>
                    </button>
                    <span class="text-xs text-(--color-on-surface-muted)">
                        Existing tools with the same name will be skipped.
                    </span>
                </div>
            </div>
        @endif
    </div>

    @selfhosted
    {{-- Quick Help (self-hosted only — shows host filesystem paths) --}}
    <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
        <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Supported Config Locations</h3>
        <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @php
                $ideConfigs = [
                    ['name' => 'Claude Desktop', 'path' => '~/Library/.../Claude/claude_desktop_config.json'],
                    ['name' => 'Claude Code', 'path' => '~/.claude.json'],
                    ['name' => 'Cursor', 'path' => '~/.cursor/mcp.json'],
                    ['name' => 'Windsurf', 'path' => '~/.codeium/windsurf/mcp_config.json'],
                    ['name' => 'Kiro', 'path' => '~/.kiro/settings/mcp.json'],
                    ['name' => 'VS Code', 'path' => '.vscode/mcp.json'],
                ];
            @endphp
            @foreach($ideConfigs as $ide)
                <div class="rounded-lg bg-(--color-surface-alt) p-3">
                    <p class="text-xs font-medium text-(--color-on-surface)">{{ $ide['name'] }}</p>
                    <p class="mt-0.5 truncate text-xs text-(--color-on-surface-muted)" title="{{ $ide['path'] }}">
                        <code>{{ $ide['path'] }}</code>
                    </p>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-(--color-on-surface-muted)">
            You can also use the CLI: <code class="rounded bg-(--color-surface-alt) px-1 py-0.5">php artisan tools:discover --import</code>
        </p>
    </div>
    @else
    {{-- Cloud replacement: no host filesystem access --}}
    <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-6">
        <h3 class="text-sm font-medium text-(--color-on-surface-muted)">Adding MCP Servers</h3>
        <p class="mt-2 text-sm text-(--color-on-surface-muted)">
            Paste or upload your MCP configuration JSON above. MCP servers are configured via the interface — no host filesystem access is required.
        </p>
    </div>
    @endselfhosted
</div>
