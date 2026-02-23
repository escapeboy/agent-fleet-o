<div>
    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search entities..."
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
        </div>
        <select wire:model.live="typeFilter"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
            <option value="">All Types</option>
            @foreach($entityTypes as $type)
                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
            @endforeach
        </select>
    </div>

    <div class="grid grid-cols-1 gap-6 {{ $selectedEntity ? 'lg:grid-cols-2' : '' }}">
        {{-- Entity List --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                            <th wire:click="sort('mention_count')"
                                class="cursor-pointer px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                                Mentions
                                @if($sortBy === 'mention_count')
                                    <span class="ml-0.5">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </th>
                            <th wire:click="sort('last_seen_at')"
                                class="cursor-pointer px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                                Last Seen
                                @if($sortBy === 'last_seen_at')
                                    <span class="ml-0.5">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($entities as $entity)
                            <tr wire:click="selectEntity('{{ $entity->id }}')"
                                class="cursor-pointer hover:bg-gray-50 {{ $selectedEntityId === $entity->id ? 'bg-primary-50' : '' }}">
                                <td class="whitespace-nowrap px-4 py-3">
                                    @php
                                        $typeColors = [
                                            'person' => 'bg-blue-100 text-blue-700',
                                            'company' => 'bg-purple-100 text-purple-700',
                                            'location' => 'bg-green-100 text-green-700',
                                            'date' => 'bg-yellow-100 text-yellow-700',
                                            'product' => 'bg-pink-100 text-pink-700',
                                            'topic' => 'bg-gray-100 text-gray-700',
                                        ];
                                        $color = $typeColors[$entity->type] ?? 'bg-gray-100 text-gray-700';
                                    @endphp
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $color }}">
                                        {{ $entity->type }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-sm font-medium text-gray-900">{{ $entity->name }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">
                                    {{ number_format($entity->mention_count) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-gray-500">
                                    {{ $entity->last_seen_at?->diffForHumans() ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400">
                                    No entities found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-200 px-4 py-3">
                {{ $entities->links() }}
            </div>
        </div>

        {{-- Selected Entity Detail --}}
        @if($selectedEntity)
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ $selectedEntity->name }}</h3>
                        <p class="text-sm text-gray-500">
                            {{ ucfirst($selectedEntity->type) }} &middot; {{ $selectedEntity->mention_count }} mention(s)
                            &middot; First seen {{ $selectedEntity->first_seen_at?->diffForHumans() }}
                        </p>
                    </div>
                    <button wire:click="selectEntity(null)" class="text-gray-400 hover:text-gray-600">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <h4 class="mb-2 text-sm font-medium text-gray-500">Linked Signals ({{ $linkedSignals->count() }})</h4>
                <div class="space-y-2">
                    @forelse($linkedSignals as $signal)
                        <div class="rounded-lg bg-gray-50 p-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-900">
                                    {{ $signal->payload['title'] ?? $signal->payload['subject'] ?? $signal->payload['summary'] ?? Str::limit(json_encode($signal->payload), 60) }}
                                </span>
                                <span class="text-xs text-gray-400">{{ $signal->received_at?->diffForHumans() }}</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ $signal->source_type }} &middot; {{ $signal->source_identifier }}
                                @if($signal->pivot->confidence < 1.0)
                                    &middot; {{ round($signal->pivot->confidence * 100) }}% confidence
                                @endif
                            </p>
                            @if($signal->pivot->context)
                                <p class="mt-1 text-xs italic text-gray-400">"{{ Str::limit($signal->pivot->context, 120) }}"</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">No linked signals.</p>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</div>
