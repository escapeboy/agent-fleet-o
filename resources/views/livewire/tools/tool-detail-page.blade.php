<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    @if($editing)
        {{-- ====== EDIT MODE ====== --}}
        <div class="rounded-xl border border-primary-200 bg-white p-6">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Edit Tool</h3>

            <div class="space-y-4">
                <x-form-input wire:model="editName" label="Name" type="text"
                    :error="$errors->first('editName')" />

                <x-form-textarea wire:model="editDescription" label="Description" rows="2"
                    :error="$errors->first('editDescription')" />

                <x-form-input wire:model.number="editTimeout" label="Timeout (seconds)" type="number" min="1" max="300"
                    :error="$errors->first('editTimeout')" />
            </div>

            <div class="mt-6 flex gap-3">
                <button wire:click="save" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save Changes
                </button>
                <button wire:click="cancelEdit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </div>
    @else
        {{-- ====== VIEW MODE ====== --}}
        {{-- Header --}}
        <div class="mb-6 flex items-start justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold text-gray-900">{{ $tool->name }}</h2>
                    <x-status-badge :status="$tool->status->value" />
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                        {{ match($tool->type->value) {
                            'mcp_stdio' => 'bg-blue-100 text-blue-800',
                            'mcp_http' => 'bg-cyan-100 text-cyan-800',
                            'built_in' => 'bg-amber-100 text-amber-800',
                            default => 'bg-gray-100 text-gray-800',
                        } }}">
                        {{ $tool->type->label() }}
                    </span>
                </div>
                @if($tool->description)
                    <p class="mt-1 text-sm text-gray-500">{{ $tool->description }}</p>
                @endif
            </div>

            <div class="flex gap-2">
                <button wire:click="toggleStatus"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $tool->status === \App\Domain\Tool\Enums\ToolStatus::Active ? 'Disable' : 'Enable' }}
                </button>
                <button wire:click="startEdit"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Edit
                </button>
                <button wire:click="deleteTool" wire:confirm="Are you sure you want to delete this tool?"
                    class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                    Delete
                </button>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="mb-6 border-b border-gray-200">
            <nav class="-mb-px flex gap-6">
                @foreach(['overview' => 'Overview', 'config' => 'Configuration', 'agents' => 'Agents'] as $tab => $label)
                    <button wire:click="$set('activeTab', '{{ $tab }}')"
                        class="border-b-2 pb-3 text-sm font-medium transition
                            {{ $activeTab === $tab
                                ? 'border-primary-600 text-primary-600'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Tab: Overview --}}
        @if($activeTab === 'overview')
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h4 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-400">Details</h4>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Slug</dt>
                            <dd class="font-mono text-xs text-gray-700">{{ $tool->slug }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Functions</dt>
                            <dd class="text-gray-700">{{ $tool->functionCount() }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Timeout</dt>
                            <dd class="text-gray-700">{{ $tool->settings['timeout'] ?? 30 }}s</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Health</dt>
                            <dd class="text-gray-700">{{ $tool->health_status ?? 'Unknown' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Created</dt>
                            <dd class="text-gray-700">{{ $tool->created_at->format('M j, Y H:i') }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h4 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-400">Tool Functions</h4>
                    @if($tool->tool_definitions)
                        <ul class="space-y-2 text-sm">
                            @foreach($tool->tool_definitions as $def)
                                <li class="rounded-lg bg-gray-50 px-3 py-2">
                                    <span class="font-mono text-xs font-medium text-gray-900">{{ $def['name'] ?? 'unnamed' }}</span>
                                    @if(isset($def['description']))
                                        <p class="mt-0.5 text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($def['description'], 100) }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @elseif($tool->isBuiltIn())
                        <p class="text-sm text-gray-500">Built-in tool functions are generated at runtime.</p>
                    @else
                        <p class="text-sm text-gray-400">No tool definitions stored. Schema discovery needed.</p>
                    @endif
                </div>
            </div>
        @endif

        {{-- Tab: Configuration --}}
        @if($activeTab === 'config')
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h4 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-400">Transport Configuration</h4>
                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-700">{{ json_encode($tool->transport_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>

            @if($tool->settings)
                <div class="mt-4 rounded-xl border border-gray-200 bg-white p-5">
                    <h4 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-400">Settings</h4>
                    <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-700">{{ json_encode($tool->settings, JSON_PRETTY_PRINT) }}</pre>
                </div>
            @endif
        @endif

        {{-- Tab: Agents --}}
        @if($activeTab === 'agents')
            <div class="rounded-xl border border-gray-200 bg-white">
                @if($agents->isEmpty())
                    <div class="px-6 py-12 text-center text-sm text-gray-400">
                        No agents are using this tool yet.
                    </div>
                @else
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Agent</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Priority</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($agents as $agent)
                                <tr class="transition hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <a href="{{ route('agents.show', $agent) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                            {{ $agent->name }}
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $agent->pivot->priority ?? 0 }}</td>
                                    <td class="px-6 py-4">
                                        <x-status-badge :status="$agent->status->value" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    @endif
</div>
