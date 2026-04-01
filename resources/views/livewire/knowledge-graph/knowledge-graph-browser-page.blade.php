<div class="space-y-6">
    {{-- Stats --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Active Facts</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Relation Types</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['relation_types']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Invalidated</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['invalidated']) }}</p>
        </div>
    </div>

    {{-- Flash messages --}}
    @if($success)
        <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ $success }}</div>
    @endif
    @if($error)
        <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ $error }}</div>
    @endif

    {{-- Filters + Add button --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="search" type="text"
                   placeholder="Search facts..."
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
        </div>
        <div>
            <select wire:model.live="edgeTypeFilter"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">All edge types</option>
                <option value="relates_to">relates_to</option>
                <option value="co_occurs">co_occurs</option>
                <option value="contains">contains</option>
                <option value="similar">similar</option>
            </select>
        </div>
        <div>
            <select wire:model.live="entityTypeFilter"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">All entity types</option>
                @foreach($entityTypes as $et)
                    <option value="{{ $et->value }}">{{ $et->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2">
            <label class="flex items-center gap-1.5 text-sm text-gray-600">
                <input wire:model.live="includeHistory" type="checkbox" class="rounded border-gray-300 text-primary-600" />
                Include history
            </label>
        </div>
        <button wire:click="$toggle('showAddForm')"
                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add Fact
        </button>
    </div>

    {{-- Add fact form --}}
    @if($showAddForm)
        <div class="rounded-xl border border-gray-200 bg-white p-5 space-y-4">
            <h3 class="text-sm font-semibold text-gray-900">Add Knowledge Fact</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Source entity name</label>
                    <input wire:model="sourceName" type="text" placeholder="e.g. Alice"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
                    @error('sourceName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Source entity type</label>
                    <select wire:model="sourceType"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                        @foreach($entityTypes as $et)
                            <option value="{{ $et->value }}">{{ $et->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Relation type</label>
                    <input wire:model="relationType" type="text" placeholder="e.g. works_at, acquired_by"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
                    @error('relationType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Target entity name</label>
                    <input wire:model="targetName" type="text" placeholder="e.g. Acme Corp"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
                    @error('targetName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Target entity type</label>
                    <select wire:model="targetType"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                        @foreach($entityTypes as $et)
                            <option value="{{ $et->value }}">{{ $et->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Fact statement</label>
                    <textarea wire:model="fact" rows="2" placeholder="e.g. Alice works at Acme Corp as Lead Engineer since 2023"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                    @error('fact') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button wire:click="$toggle('showAddForm')"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button wire:click="addFact"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">Save Fact</button>
            </div>
        </div>
    @endif

    {{-- Facts table --}}
    <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
        @if($facts->isEmpty())
            <div class="py-12 text-center text-sm text-gray-500">
                No knowledge facts found.
                @if($search || $edgeTypeFilter || $entityTypeFilter)
                    Try clearing your filters.
                @else
                    Facts are created automatically when agents process signals, or add them manually above.
                @endif
            </div>
        @else
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Relation</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Target</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Fact</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Valid at</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($facts as $fact)
                        <tr class="{{ $fact->invalid_at ? 'bg-gray-50 opacity-60' : 'hover:bg-gray-50' }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $fact->sourceEntity?->name ?? '—' }}</div>
                                <div class="text-xs text-gray-500">{{ $fact->sourceEntity?->type ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                    {{ $fact->relation_type }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $fact->targetEntity?->name ?? '—' }}</div>
                                <div class="text-xs text-gray-500">{{ $fact->targetEntity?->type ?? '' }}</div>
                            </td>
                            <td class="max-w-xs px-4 py-3">
                                <p class="truncate text-gray-700" title="{{ $fact->fact }}">{{ $fact->fact }}</p>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                                {{ $fact->valid_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($fact->invalid_at)
                                    <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">invalidated</span>
                                @else
                                    <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">active</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    @if(! $fact->invalid_at)
                                        <button wire:click="invalidateFact('{{ $fact->id }}')"
                                                class="text-xs text-amber-600 hover:text-amber-800"
                                                title="Invalidate fact">Invalidate</button>
                                    @endif
                                    <button wire:click="deleteFact('{{ $fact->id }}')"
                                            wire:confirm="Delete this fact permanently?"
                                            class="text-xs text-red-500 hover:text-red-700"
                                            title="Delete permanently">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="border-t border-gray-200 px-4 py-3">
                {{ $facts->links() }}
            </div>
        @endif
    </div>
</div>
