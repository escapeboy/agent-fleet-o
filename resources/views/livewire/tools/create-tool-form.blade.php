<div class="mx-auto max-w-3xl">
    {{-- Progress Steps --}}
    <div class="mb-8 flex items-center justify-center gap-2">
        @foreach([1 => 'Basics', 2 => 'Configuration', 3 => 'Review'] as $num => $label)
            <div class="flex items-center gap-2">
                <div class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium
                    {{ $step >= $num ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-500' }}">
                    {{ $num }}
                </div>
                <span class="text-sm {{ $step >= $num ? 'text-gray-900' : 'text-gray-400' }}">{{ $label }}</span>
            </div>
            @if($num < 3)
                <div class="mx-2 h-px w-8 {{ $step > $num ? 'bg-primary-600' : 'bg-gray-200' }}"></div>
            @endif
        @endforeach
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-6">
        {{-- Step 1: Basics --}}
        @if($step === 1)
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Tool Basics</h3>
            <div class="space-y-4">
                <x-form-input wire:model="name" label="Name" type="text" placeholder="e.g. GitHub MCP Server"
                    :error="$errors->first('name')" hint="A descriptive name for this tool"
                    toolparamdescription="Tool name — descriptive identifier" />

                <x-form-textarea wire:model="description" label="Description" rows="2"
                    placeholder="What does this tool do?"
                    :error="$errors->first('description')"
                    toolparamdescription="What this tool does and its capabilities" />

                <x-form-select wire:model.live="type" label="Type" toolparamdescription="Tool type: mcp_stdio (local MCP), mcp_http (remote MCP), or built_in (bash/filesystem/browser)">
                    @foreach($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </x-form-select>

                @if($type === 'mcp_stdio')
                    <p class="text-sm text-gray-500">Connects to an MCP server via stdio (e.g. <code class="text-xs">npx @modelcontextprotocol/server-github</code>)</p>
                @elseif($type === 'mcp_http')
                    <p class="text-sm text-gray-500">Connects to an MCP server over HTTP/SSE</p>
                @elseif($type === 'mcp_bridge')
                    <p class="text-sm text-gray-500">Connects to an MCP server running on your bridge daemon (e.g. Playwright, filesystem)</p>
                @elseif($type === 'built_in')
                    @selfhosted
                    <p class="text-sm text-gray-500">Use host machine capabilities (bash, filesystem) with sandboxing</p>
                    @else
                    <p class="text-sm text-gray-500">Connect agents to remote servers via SSH for secure remote command execution</p>
                    @endselfhosted
                @endif

                <x-form-select wire:model="riskLevel" label="Risk Level" hint="Controls tool availability in Watcher mode projects">
                    <option value="">Not classified</option>
                    @foreach($riskLevels as $level)
                        <option value="{{ $level->value }}">{{ $level->label() }}</option>
                    @endforeach
                </x-form-select>
            </div>
        @endif

        {{-- Step 2: Transport Configuration --}}
        @if($step === 2)
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Configuration</h3>
            <div class="space-y-4">
                @if($type === 'mcp_stdio')
                    <x-form-input wire:model="mcpCommand" label="Command" type="text"
                        placeholder="npx" :error="$errors->first('mcpCommand')"
                        hint="The executable to run" />

                    <x-form-input wire:model="mcpArgs" label="Arguments (comma-separated)" type="text"
                        placeholder="@modelcontextprotocol/server-github"
                        hint="Command-line arguments, separated by commas" />

                    <x-form-textarea wire:model="mcpEnv" label="Environment Variables" rows="3"
                        placeholder="GITHUB_TOKEN=ghp_xxx&#10;NODE_ENV=production"
                        hint="One per line, KEY=VALUE format" />
                @endif

                @if($type === 'mcp_http')
                    <x-form-input wire:model="mcpUrl" label="Server URL" type="url"
                        placeholder="https://mcp-server.example.com/sse"
                        :error="$errors->first('mcpUrl')" />

                    <x-form-textarea wire:model="mcpHeaders" label="Custom Headers" rows="2"
                        placeholder="Authorization=Bearer xxx"
                        hint="One per line, KEY=VALUE format" />
                @endif

                @if($type === 'mcp_bridge')
                    <x-form-input wire:model="bridgeServerName" label="Bridge Server Name" type="text"
                        placeholder="playwright"
                        :error="$errors->first('bridgeServerName')"
                        hint="Must match a server name reported by your bridge daemon" />
                @endif

                @if($type === 'built_in')
                    <x-form-select wire:model.live="builtInKind" label="Kind">
                        @foreach($builtInKinds as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </x-form-select>

                    @selfhosted
                    @if($builtInKind === 'bash')
                        <x-form-textarea wire:model="allowedCommands" label="Allowed Commands (comma-separated)" rows="2"
                            hint="Only these binaries can be executed" />

                        <x-form-textarea wire:model="allowedPaths" label="Allowed Paths (comma-separated)" rows="2"
                            hint="Restrict working directory to these paths" />
                    @endif

                    @if($builtInKind === 'filesystem')
                        <x-form-textarea wire:model="allowedPaths" label="Allowed Paths (comma-separated)" rows="2"
                            hint="Restrict file access to these directories" />

                        <x-form-checkbox wire:model="readOnly" label="Read-only mode" />
                    @endif
                    @endselfhosted

                    @if($builtInKind === 'ssh')
                        <x-form-input wire:model="sshHost" label="Host" type="text" placeholder="example.com"
                            :error="$errors->first('sshHost')" hint="Hostname or IP of the remote server" />

                        <x-form-input wire:model.number="sshPort" label="Port" type="number" min="1" max="65535"
                            :error="$errors->first('sshPort')" />

                        <x-form-input wire:model="sshUsername" label="Username" type="text" placeholder="deploy"
                            :error="$errors->first('sshUsername')" />

                        <x-form-select wire:model="sshCredentialId" label="SSH Key Credential"
                            :error="$errors->first('sshCredentialId')">
                            <option value="">-- Select SSH key --</option>
                            @foreach($sshCredentials as $cred)
                                <option value="{{ $cred->id }}">{{ $cred->name }}</option>
                            @endforeach
                        </x-form-select>

                        @if($sshCredentials->isEmpty())
                            <p class="text-sm text-amber-600">
                                No active SSH key credentials found.
                                <a href="{{ route('credentials.create') }}" class="underline">Add one first</a>.
                            </p>
                        @endif

                        <x-form-textarea wire:model="sshAllowedCommands" label="Allowed Commands (comma-separated, optional)" rows="2"
                            hint="Leave empty to allow all commands permitted by org policy" />
                    @endif
                @endif

                {{-- Credential section for MCP tools --}}
                @if($type !== 'built_in')
                    <div class="rounded-lg border border-gray-200 p-4 space-y-4">
                        <div>
                            <p class="text-sm font-medium text-gray-700 mb-2">API Credential</p>
                            <div class="flex gap-6">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" wire:model.live="credentialMode" value="inline"
                                        class="text-primary-600 focus:ring-primary-500">
                                    <span class="text-sm text-gray-700">Inline API Key</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" wire:model.live="credentialMode" value="reference"
                                        class="text-primary-600 focus:ring-primary-500">
                                    <span class="text-sm text-gray-700">Link to Credential</span>
                                </label>
                            </div>
                        </div>

                        @if($credentialMode === 'inline')
                            <x-form-input wire:model="apiKey" label="API Key" type="password"
                                placeholder="Stored encrypted with per-team key"
                                hint="Encrypted at rest using per-team envelope encryption" />
                        @else
                            <x-form-select wire:model="credentialId" label="Credential"
                                hint="Reuse an existing credential — no need to re-enter the key">
                                <option value="">-- Select credential --</option>
                                @foreach($availableCredentials as $cred)
                                    <option value="{{ $cred->id }}">{{ $cred->name }} ({{ $cred->credential_type->label() }})</option>
                                @endforeach
                            </x-form-select>
                            @if($availableCredentials->isEmpty())
                                <p class="text-sm text-amber-600">
                                    No active credentials found.
                                    <a href="{{ route('credentials.create') }}" class="underline">Add one first</a>.
                                </p>
                            @endif
                        @endif

                        <x-form-input wire:model="credentialEnvVar" label="Environment Variable Name" type="text"
                            placeholder="API_KEY"
                            hint="The env var injected into the MCP process with the secret value" />
                    </div>
                @endif

                <x-form-input wire:model.number="timeout" label="Timeout (seconds)" type="number" min="1" max="300" />

                @if($type === 'mcp_stdio' || $type === 'mcp_http' || $type === 'mcp_bridge')
                    <x-form-textarea wire:model="toolDefinitionsJson" label="Tool Definitions (JSON, optional)" rows="4" mono="true"
                        placeholder='[{"name": "search", "description": "Search repos", "input_schema": {"type": "object", "properties": {}}}]'
                        hint="Paste the tools/list output from the MCP server. Can be auto-discovered later." />
                @endif
            </div>
        @endif

        {{-- Step 3: Review --}}
        @if($step === 3)
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Review & Create</h3>
            <div class="space-y-3 text-sm">
                <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                    <div class="text-gray-500">Name</div>
                    <div class="font-medium">{{ $name }}</div>

                    <div class="text-gray-500">Type</div>
                    <div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ match($type) {
                                'mcp_stdio' => 'bg-blue-100 text-blue-800',
                                'mcp_http' => 'bg-cyan-100 text-cyan-800',
                                'mcp_bridge' => 'bg-purple-100 text-purple-800',
                                'built_in' => 'bg-amber-100 text-amber-800',
                                default => 'bg-gray-100 text-gray-800',
                            } }}">
                            {{ \App\Domain\Tool\Enums\ToolType::from($type)->label() }}
                        </span>
                    </div>

                    @if($description)
                        <div class="text-gray-500">Description</div>
                        <div>{{ $description }}</div>
                    @endif

                    @if($riskLevel)
                        <div class="text-gray-500">Risk Level</div>
                        <div>
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ \App\Domain\Tool\Enums\ToolRiskLevel::from($riskLevel)->color() }}">
                                {{ \App\Domain\Tool\Enums\ToolRiskLevel::from($riskLevel)->label() }}
                            </span>
                        </div>
                    @endif

                    <div class="text-gray-500">Timeout</div>
                    <div>{{ $timeout }}s</div>

                    @if($type === 'mcp_stdio')
                        <div class="text-gray-500">Command</div>
                        <div class="font-mono text-xs">{{ $mcpCommand }} {{ $mcpArgs }}</div>
                    @elseif($type === 'mcp_http')
                        <div class="text-gray-500">URL</div>
                        <div class="font-mono text-xs">{{ $mcpUrl }}</div>
                    @elseif($type === 'mcp_bridge')
                        <div class="text-gray-500">Bridge Server</div>
                        <div class="font-mono text-xs">{{ $bridgeServerName }}</div>
                    @elseif($type === 'built_in')
                        <div class="text-gray-500">Kind</div>
                        <div>{{ \App\Domain\Tool\Enums\BuiltInToolKind::from($builtInKind)->label() }}</div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Actions --}}
        <div class="mt-6 flex justify-between">
            @if($step > 1)
                <button wire:click="prevStep" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Back
                </button>
            @else
                <div></div>
            @endif

            @if($step < 3)
                <button wire:click="nextStep" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Next
                </button>
            @else
                <button wire:click="save" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Create Tool
                </button>
            @endif
        </div>
    </div>
</div>

@script
<script>
if (window.FleetQWebMcp?.isAvailable()) {
    window.FleetQWebMcp.registerTool({
        name: 'create_tool',
        description: 'Register a new LLM tool (MCP server or built-in tool)',
        inputSchema: {
            type: 'object',
            properties: {
                name: { type: 'string', description: 'Tool name — descriptive identifier' },
                description: { type: 'string', description: 'What this tool does and its capabilities' },
                type: { type: 'string', description: 'Tool type: mcp_stdio, mcp_http, mcp_bridge, or built_in' },
                mcp_command: { type: 'string', description: 'Command for mcp_stdio (e.g., npx)' },
                mcp_args: { type: 'string', description: 'Arguments for mcp_stdio (comma-separated)' },
                mcp_url: { type: 'string', description: 'Server URL for mcp_http' },
                bridge_server_name: { type: 'string', description: 'Server name for mcp_bridge (must match bridge daemon)' },
                built_in_kind: { type: 'string', description: 'Kind for built_in: bash, filesystem, or ssh' },
                timeout: { type: 'number', description: 'Timeout in seconds (1-300)' },
            },
            required: ['name', 'type'],
        },
        async execute(params) {
            $wire.set('name', params.name);
            if (params.description) $wire.set('description', params.description);
            $wire.set('type', params.type);
            if (params.mcp_command) $wire.set('mcpCommand', params.mcp_command);
            if (params.mcp_args) $wire.set('mcpArgs', params.mcp_args);
            if (params.mcp_url) $wire.set('mcpUrl', params.mcp_url);
            if (params.bridge_server_name) $wire.set('bridgeServerName', params.bridge_server_name);
            if (params.built_in_kind) $wire.set('builtInKind', params.built_in_kind);
            if (params.timeout) $wire.set('timeout', params.timeout);
            $wire.set('step', 3);
            await $wire.save();
            return { success: true, message: 'Tool created' };
        },
    });
}
</script>
@endscript
