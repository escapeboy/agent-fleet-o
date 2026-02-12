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
                    :error="$errors->first('name')" hint="A descriptive name for this tool" />

                <x-form-textarea wire:model="description" label="Description" rows="2"
                    placeholder="What does this tool do?"
                    :error="$errors->first('description')" />

                <x-form-select wire:model.live="type" label="Type">
                    @foreach($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </x-form-select>

                @if($type === 'mcp_stdio')
                    <p class="text-sm text-gray-500">Connects to an MCP server via stdio (e.g. <code class="text-xs">npx @modelcontextprotocol/server-github</code>)</p>
                @elseif($type === 'mcp_http')
                    <p class="text-sm text-gray-500">Connects to an MCP server over HTTP/SSE</p>
                @elseif($type === 'built_in')
                    <p class="text-sm text-gray-500">Use host machine capabilities (bash, filesystem) with sandboxing</p>
                @endif
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

                @if($type === 'built_in')
                    <x-form-select wire:model.live="builtInKind" label="Kind">
                        @foreach($builtInKinds as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </x-form-select>

                    @if($builtInKind === 'bash')
                        <x-form-textarea wire:model="allowedCommands" label="Allowed Commands (comma-separated)" rows="2"
                            hint="Only these binaries can be executed" />
                    @endif

                    <x-form-textarea wire:model="allowedPaths" label="Allowed Paths (comma-separated)" rows="2"
                        hint="Restrict file access to these directories" />

                    @if($builtInKind === 'filesystem')
                        <x-form-checkbox wire:model="readOnly" label="Read-only mode" />
                    @endif
                @endif

                {{-- Shared fields --}}
                @if($type !== 'built_in')
                    <x-form-input wire:model="apiKey" label="API Key (optional)" type="password"
                        placeholder="Stored encrypted"
                        hint="Will be encrypted at rest" />
                @endif

                <x-form-input wire:model.number="timeout" label="Timeout (seconds)" type="number" min="1" max="300" />

                @if($type === 'mcp_stdio' || $type === 'mcp_http')
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

                    <div class="text-gray-500">Timeout</div>
                    <div>{{ $timeout }}s</div>

                    @if($type === 'mcp_stdio')
                        <div class="text-gray-500">Command</div>
                        <div class="font-mono text-xs">{{ $mcpCommand }} {{ $mcpArgs }}</div>
                    @elseif($type === 'mcp_http')
                        <div class="text-gray-500">URL</div>
                        <div class="font-mono text-xs">{{ $mcpUrl }}</div>
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
