<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search memory content..." class="pl-10">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="agentFilter">
            <option value="">All Agents</option>
            @foreach($agents as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="projectFilter">
            <option value="">All Projects</option>
            @foreach($projects as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="sourceTypeFilter">
            <option value="">All Sources</option>
            @foreach($sourceTypes as $type)
                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
            @endforeach
        </x-form-select>
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
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Agent</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Content</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Project</th>
                    <th wire:click="sortBy('created_at')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Created {!! $sortIcon('created_at') !!}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($memories as $memory)
                    <tr class="cursor-pointer transition hover:bg-gray-50" wire:click="toggleExpand('{{ $memory->id }}')">
                        <td class="px-6 py-4 text-sm font-medium text-primary-600">
                            {{ $memory->agent?->name ?? '-' }}
                        </td>
                        <td class="max-w-xs truncate px-6 py-4 text-sm text-gray-500">
                            {{ Str::limit($memory->content, 80) }}
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                {{ $memory->source_type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            {{ $memory->project?->name ?? '-' }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            {{ $memory->created_at->diffForHumans() }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">
                            <svg class="h-4 w-4 transition {{ $expandedId === $memory->id ? 'rotate-90' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </td>
                    </tr>

                    @if($expandedId === $memory->id)
                        <tr>
                            <td colspan="6" class="bg-gray-50 px-6 py-4">
                                <div class="space-y-3">
                                    {{-- Full content --}}
                                    <div>
                                        <h4 class="text-xs font-medium uppercase text-gray-500">Content</h4>
                                        <div class="mt-1 rounded-lg bg-gray-900 p-4">
                                            <pre class="overflow-auto whitespace-pre-wrap text-xs text-green-400">{{ $memory->content }}</pre>
                                        </div>
                                    </div>

                                    {{-- Metadata --}}
                                    @if($memory->metadata && count($memory->metadata) > 0)
                                        <div>
                                            <h4 class="text-xs font-medium uppercase text-gray-500">Metadata</h4>
                                            <div class="mt-1 rounded-lg bg-gray-900 p-4">
                                                <pre class="overflow-auto text-xs text-green-400">{{ json_encode($memory->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Actions --}}
                                    <div class="flex items-center gap-4 pt-2">
                                        <span class="text-xs text-gray-400">ID: {{ $memory->id }}</span>
                                        @if($memory->source_id)
                                            <span class="text-xs text-gray-400">Source ID: {{ $memory->source_id }}</span>
                                        @endif
                                        <span class="text-xs text-gray-400">{{ $memory->created_at->format('Y-m-d H:i:s') }}</span>
                                        <button wire:click.stop="deleteMemory('{{ $memory->id }}')"
                                            wire:confirm="Are you sure you want to delete this memory?"
                                            class="ml-auto text-sm text-red-600 hover:text-red-800">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400">
                            No memories found. Agents will store memories as they execute tasks.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $memories->links() }}
    </div>
</div>
