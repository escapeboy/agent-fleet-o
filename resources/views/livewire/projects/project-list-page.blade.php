<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search projects...">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="statusFilter">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="typeFilter">
            <option value="">All Types</option>
            @foreach($types as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('projects.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Project
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
                    <th wire:click="sortBy('title')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Title {!! $sortIcon('title') !!}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Schedule</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Run</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Success Rate</th>
                    <th wire:click="sortBy('created_at')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Created {!! $sortIcon('created_at') !!}
                    </th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($projects as $project)
                    <tr class="transition hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <a href="{{ route('projects.show', $project) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                {{ $project->title }}
                            </a>
                            @if($project->description)
                                <p class="mt-0.5 max-w-xs truncate text-xs text-gray-400">{{ $project->description }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium
                                {{ $project->type === \App\Domain\Project\Enums\ProjectType::Continuous ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700' }}">
                                {{ $project->type->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <x-status-badge :status="$project->status->value" />
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            @if($project->schedule && $project->type === \App\Domain\Project\Enums\ProjectType::Continuous)
                                <span class="text-xs">{{ $project->schedule->frequency->label() }}</span>
                                @if($project->schedule->next_run_at)
                                    <br><span class="text-xs text-gray-400">Next: {{ $project->schedule->next_run_at->diffForHumans() }}</span>
                                @endif
                            @else
                                <span class="text-xs text-gray-400">One-shot</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            @if($project->last_run_at)
                                {{ $project->last_run_at->diffForHumans() }}
                            @else
                                <span class="text-gray-400">Never</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            @php $rate = $project->successRate(); @endphp
                            @if($rate !== null)
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-16 rounded-full bg-gray-200">
                                        <div class="h-2 rounded-full {{ $rate >= 80 ? 'bg-green-500' : ($rate >= 50 ? 'bg-yellow-500' : 'bg-red-500') }}" style="width: {{ $rate }}%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500">{{ round($rate) }}%</span>
                                </div>
                            @else
                                <span class="text-xs text-gray-400">N/A</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $project->created_at->diffForHumans() }}</td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if($project->status->canTransitionTo(\App\Domain\Project\Enums\ProjectStatus::Paused))
                                    <button wire:click="pause('{{ $project->id }}')"
                                        class="rounded p-1 text-gray-400 hover:bg-yellow-50 hover:text-yellow-600" title="Pause">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </button>
                                @endif
                                @if($project->status === \App\Domain\Project\Enums\ProjectStatus::Paused)
                                    <button wire:click="resume('{{ $project->id }}')"
                                        class="rounded p-1 text-gray-400 hover:bg-green-50 hover:text-green-600" title="Resume">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </button>
                                @endif
                                @if($project->status->canTransitionTo(\App\Domain\Project\Enums\ProjectStatus::Archived))
                                    <button wire:click="archive('{{ $project->id }}')"
                                        wire:confirm="Archive this project? It cannot be reactivated."
                                        class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Archive">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-400">
                            No projects found. <a href="{{ route('projects.create') }}" class="text-primary-600 hover:underline">Create your first project!</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $projects->links() }}
    </div>
</div>
