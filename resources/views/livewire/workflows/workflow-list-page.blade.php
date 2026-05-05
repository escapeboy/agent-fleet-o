<div>
    {{-- Toolbar --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false" toolname="search_workflows" tooldescription="Filter workflows by status and search query">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search workflows..." class="pl-10" toolparamdescription="Free-text search across workflow names and descriptions">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="statusFilter" toolparamdescription="Filter by workflow status: draft, active, archived">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>

        @if($canCreate)
            <a href="{{ route('workflows.create') }}"
                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                New Workflow
            </a>
        @else
            <span class="rounded-lg bg-gray-300 px-4 py-2 text-sm font-medium text-gray-500 cursor-not-allowed" title="Plan limit reached">
                New Workflow
            </span>
        @endif
    </form>

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
                    <th wire:click="sortBy('status')" class="cursor-pointer px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Status {!! $sortIcon('status') !!}
                    </th>
                    <th class="hidden md:table-cell px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Nodes</th>
                    <th class="hidden md:table-cell px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Experiments</th>
                    <th class="hidden md:table-cell px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Version</th>
                    <th class="hidden md:table-cell px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Est. Cost</th>
                    <th wire:click="sortBy('created_at')" class="hidden md:table-cell cursor-pointer px-3 py-2 md:px-6 md:py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Created {!! $sortIcon('created_at') !!}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($workflows as $workflow)
                    <tr class="transition hover:bg-gray-50">
                        <td class="px-3 py-3 md:px-6 md:py-4">
                            <a href="{{ route('workflows.show', $workflow) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                {{ $workflow->name }}
                            </a>
                            @if($workflow->description)
                                <p class="mt-0.5 truncate text-xs text-gray-400">{{ $workflow->description }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-3 md:px-6 md:py-4">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $workflow->status->color() }}-100 text-{{ $workflow->status->color() }}-800">
                                {{ $workflow->status->label() }}
                            </span>
                        </td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $workflow->nodes_count }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $workflow->experiments_count }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">v{{ $workflow->version }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">
                            @if($workflow->estimated_cost_credits)
                                {{ number_format($workflow->estimated_cost_credits) }} cr
                            @else
                                <span class="text-gray-300">-</span>
                            @endif
                        </td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $workflow->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-400">
                            No workflows found. Create your first one!
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $workflows->links() }}
    </div>
</div>
