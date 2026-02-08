<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search agents..." class="pl-10">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="statusFilter">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ ucfirst($status->value) }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('agents.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Agent
        </a>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    @php
                        $sortIcon = fn($field) => $sortField === $field
                            ? ($sortDirection === 'asc' ? '&#9650;' : '&#9660;')
                            : '<span class="text-gray-300">&#9650;</span>';
                    @endphp
                    <th wire:click="sortBy('name')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Name {!! $sortIcon('name') !!}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Role</th>
                    <th wire:click="sortBy('status')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Status {!! $sortIcon('status') !!}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Provider</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Skills</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Budget</th>
                    <th wire:click="sortBy('created_at')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Created {!! $sortIcon('created_at') !!}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($agents as $agent)
                    <tr class="transition hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <a href="{{ route('agents.show', $agent) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                {{ $agent->name }}
                            </a>
                            @if($agent->goal)
                                <p class="mt-0.5 max-w-xs truncate text-xs text-gray-400">{{ $agent->goal }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $agent->role ?? '-' }}</td>
                        <td class="px-6 py-4">
                            <x-status-badge :status="$agent->status->value" />
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $agent->provider }}/{{ $agent->model }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $agent->skills_count }}</td>
                        <td class="px-6 py-4">
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
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $agent->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-400">
                            No agents found. Create your first one!
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $agents->links() }}
    </div>
</div>
