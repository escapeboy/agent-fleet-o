<div class="space-y-6">
    {{-- Stats --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Communities</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Largest Cluster</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['largest']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Clustered Entities</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['entities']) }}</p>
        </div>
    </div>

    {{-- Flash messages --}}
    @if($success)
        <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ $success }}</div>
    @endif
    @if($error)
        <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ $error }}</div>
    @endif

    {{-- Filters + Rebuild --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text"
                   placeholder="Search communities by label..."
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
        </div>
        @can('edit-content')
            <button wire:click="rebuild" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60">
                <i class="fa-solid fa-arrows-rotate text-base" wire:loading.remove wire:target="rebuild"></i>
                <i class="fa-solid fa-spinner fa-spin text-base" wire:loading wire:target="rebuild"></i>
                Rebuild Communities
            </button>
        @endcan
    </div>

    {{-- Communities list --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Label</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Size</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Top Entities</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($communities as $community)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 align-top">
                            <p class="text-sm font-medium text-gray-900">{{ $community->label ?: 'Unlabeled cluster' }}</p>
                            @if($community->summary)
                                <p class="mt-0.5 text-xs text-gray-500 line-clamp-2">{{ $community->summary }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top">
                            <span class="inline-flex items-center rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                                {{ number_format($community->size) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 align-top">
                            <div class="flex flex-wrap gap-1">
                                @foreach(array_slice($community->top_entities ?? [], 0, 5) as $entity)
                                    <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                                        {{ $entity['name'] ?? '—' }}
                                        @if(isset($entity['type']))
                                            <span class="text-gray-400">{{ $entity['type'] }}</span>
                                        @endif
                                    </span>
                                @endforeach
                                @if(empty($community->top_entities))
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 align-top text-right">
                            <button wire:click="toggleExpanded('{{ $community->id }}')"
                                    class="text-xs font-medium text-primary-600 hover:text-primary-700">
                                {{ $expandedId === $community->id ? 'Hide members' : 'View members' }}
                            </button>
                        </td>
                    </tr>
                    @if($expandedId === $community->id)
                        <tr class="bg-gray-50">
                            <td colspan="4" class="px-4 py-3">
                                <p class="mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Member entity IDs ({{ count($community->entity_ids ?? []) }})
                                </p>
                                <div class="flex flex-wrap gap-1">
                                    @foreach($community->entity_ids ?? [] as $entityId)
                                        <span class="inline-flex items-center rounded-md bg-white border border-gray-200 px-2 py-0.5 font-mono text-[11px] text-gray-600">
                                            {{ $entityId }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-12 text-center text-sm text-gray-500">
                            No communities yet. Build the knowledge graph, then rebuild communities to surface clusters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $communities->links() }}</div>
</div>
