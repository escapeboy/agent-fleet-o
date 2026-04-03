<div>
    {{-- Toolbar --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false" toolname="search_agents" tooldescription="Filter agents by status and search query">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search agents..." class="pl-10" toolparamdescription="Free-text search across agent names, roles, and goals">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="statusFilter" toolparamdescription="Filter by agent status: active, disabled">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ ucfirst($status->value) }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('agents.templates') }}"
            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Templates
        </a>

        <a href="{{ route('agents.quick') }}"
            class="inline-flex items-center gap-1.5 rounded-lg border border-primary-300 bg-primary-50 px-4 py-2 text-sm font-medium text-primary-700 hover:bg-primary-100">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Quick Agent
        </a>

        <button wire:click="$set('showImportModal', true)"
            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Import
        </button>

        @if($canCreate)
            <a href="{{ route('agents.create') }}"
                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                New Agent
            </a>
        @else
            <span class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed" title="Plan limit reached">
                New Agent
            </span>
        @endif
    </form>

    {{-- Import Modal --}}
    @if($showImportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showImportModal', false)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-lg font-semibold text-gray-900">Import Agent Workspace</h3>
                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">File (ZIP or YAML, max 10MB)</label>
                        <input type="file" wire:model="importFile" accept=".zip,.yaml,.yml"
                            class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100" />
                        @error('importFile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <x-form-select wire:model.live="importMode" label="Mode">
                        <option value="create">Create new agent</option>
                        <option value="merge">Merge into existing agent</option>
                    </x-form-select>

                    @if($importMode === 'merge')
                        <x-form-select wire:model="mergeAgentId" label="Target Agent">
                            <option value="">Select an agent...</option>
                            @foreach(\App\Domain\Agent\Models\Agent::orderBy('name')->get() as $a)
                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                            @endforeach
                        </x-form-select>
                    @endif

                    <div class="flex justify-end gap-2 border-t border-gray-200 pt-4">
                        <button wire:click="$set('showImportModal', false)"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button wire:click="importWorkspace" wire:loading.attr="disabled"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                            <span wire:loading.remove wire:target="importWorkspace">Import</span>
                            <span wire:loading wire:target="importWorkspace">Importing...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
        <table class="w-full table-fixed divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    @php
                        $sortIcon = fn($field) => $sortField === $field
                            ? ($sortDirection === 'asc' ? '&#9650;' : '&#9660;')
                            : '<span class="text-gray-300">&#9650;</span>';
                    @endphp
                    <th wire:click="sortBy('name')" class="cursor-pointer px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Name {!! $sortIcon('name') !!}
                    </th>
                    <th class="hidden md:table-cell px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Role</th>
                    <th wire:click="sortBy('status')" class="cursor-pointer px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Status {!! $sortIcon('status') !!}
                    </th>
                    <th class="hidden md:table-cell px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Provider</th>
                    <th class="hidden md:table-cell px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Skills</th>
                    <th class="hidden md:table-cell px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Risk</th>
                    <th class="hidden md:table-cell px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Budget</th>
                    <th wire:click="sortBy('created_at')" class="hidden md:table-cell cursor-pointer px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Created {!! $sortIcon('created_at') !!}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($agents as $agent)
                    <tr class="transition hover:bg-gray-50">
                        <td class="px-3 py-3 md:px-6 md:py-4">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <a href="{{ route('agents.show', $agent) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                    {{ $agent->name }}
                                </a>
                                @if($agent->pending_evolution_proposals_count > 0)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                        </svg>
                                        Improving
                                    </span>
                                @endif
                            </div>
                            @if($agent->goal)
                                <p class="mt-0.5 truncate text-xs text-gray-400">{{ $agent->goal }}</p>
                            @endif
                            @php $tier = \App\Domain\Agent\Enums\ExecutionTier::fromConfig($agent->config ?? []); @endphp
                            <span class="mt-1 inline-block rounded-full px-1.5 py-0.5 text-[10px] font-semibold {{ $tier->badgeClass() }}">{{ $tier->shortLabel() }}</span>
                        </td>
                        <td class="hidden md:table-cell px-3 py-3 md:px-6 md:py-4 text-sm text-gray-500">{{ $agent->role ?? '-' }}</td>
                        <td class="px-3 py-3 md:px-6 md:py-4">
                            <div class="flex items-center gap-2">
                                <x-agent-status-indicator :status="$agent->status->value" size="sm" />
                                <x-status-badge :status="$agent->status->value" />
                            </div>
                        </td>
                        <td class="hidden md:table-cell px-3 py-3 md:px-6 md:py-4 text-sm text-gray-500">{{ $agent->provider }}/{{ $agent->model }}</td>
                        <td class="hidden md:table-cell px-3 py-3 md:px-6 md:py-4 text-sm text-gray-500">{{ $agent->skills_count }}</td>
                        <td class="hidden md:table-cell px-3 py-3 md:px-6 md:py-4">
                            @if($agent->risk_score !== null)
                                @php
                                    $score = (float) $agent->risk_score;
                                    $color = $score > 60 ? 'red' : ($score > 40 ? 'yellow' : 'green');
                                    $label = $score > 60 ? 'High' : ($score > 40 ? 'Medium' : 'Low');
                                @endphp
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $color === 'red' ? 'bg-red-100 text-red-700' : ($color === 'yellow' ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700') }}">
                                    {{ $label }} ({{ number_format($score, 0) }})
                                </span>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="hidden md:table-cell px-3 py-3 md:px-6 md:py-4">
                            @if($agent->budget_cap_credits)
                                @php $pct = min(100, round(($agent->budget_spent_credits / $agent->budget_cap_credits) * 100)); @endphp
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-16 rounded-full bg-gray-200">
                                        <div class="h-2 rounded-full {{ $pct > 80 ? 'bg-red-500' : ($pct > 50 ? 'bg-yellow-500' : 'bg-green-500') }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500">{{ $pct }}%</span>
                                </div>
                            @else
                                <span class="text-xs text-gray-400">No cap</span>
                            @endif
                        </td>
                        <td class="hidden md:table-cell px-3 py-3 md:px-6 md:py-4 text-sm text-gray-500">{{ $agent->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-400">
                            No agents found. Create your first one!
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $agents->links() }}
    </div>
</div>
