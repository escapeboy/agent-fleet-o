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
            <i class="fa-solid fa-plus text-base"></i>
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
                                    <button wire:click="viewFact('{{ $fact->id }}')"
                                            class="text-xs text-blue-600 hover:text-blue-800"
                                            title="View details">View</button>
                                    @if(! $fact->invalid_at)
                                        <button wire:click="editFact('{{ $fact->id }}')"
                                                class="text-xs text-indigo-600 hover:text-indigo-800"
                                                title="Edit fact">Edit</button>
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

    {{-- View Fact Modal --}}
    @if($viewingFact)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="closeView">
            <div class="w-full max-w-lg rounded-xl border border-gray-200 bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-gray-900">Fact Details</h3>
                    <button wire:click="closeView" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>
                <dl class="space-y-3 text-sm">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Source</dt>
                            <dd class="text-gray-900">{{ $viewingFact['source_name'] }}</dd>
                            <dd class="text-xs text-gray-500">{{ $viewingFact['source_type'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Target</dt>
                            <dd class="text-gray-900">{{ $viewingFact['target_name'] }}</dd>
                            <dd class="text-xs text-gray-500">{{ $viewingFact['target_type'] }}</dd>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Relation Type</dt>
                            <dd><span class="inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $viewingFact['relation_type'] }}</span></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Edge Type</dt>
                            <dd class="text-gray-900">{{ $viewingFact['edge_type'] }}</dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500">Fact</dt>
                        <dd class="text-gray-900 whitespace-pre-wrap">{{ $viewingFact['fact'] }}</dd>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Valid at</dt>
                            <dd class="text-gray-700">{{ $viewingFact['valid_at'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Invalid at</dt>
                            <dd class="text-gray-700">{{ $viewingFact['invalid_at'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Expired at</dt>
                            <dd class="text-gray-700">{{ $viewingFact['expired_at'] ?? '—' }}</dd>
                        </div>
                    </div>
                    @if(!empty($viewingFact['attributes']))
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Attributes</dt>
                            <dd class="mt-1 rounded-lg bg-gray-50 p-2 text-xs font-mono text-gray-700">{{ json_encode($viewingFact['attributes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</dd>
                        </div>
                    @endif
                    <div class="grid grid-cols-2 gap-4 border-t border-gray-100 pt-3">
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Created</dt>
                            <dd class="text-gray-700">{{ $viewingFact['created_at'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-gray-500">Updated</dt>
                            <dd class="text-gray-700">{{ $viewingFact['updated_at'] ?? '—' }}</dd>
                        </div>
                    </div>
                </dl>
                <div class="mt-5 flex justify-end">
                    <button wire:click="closeView" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Close</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Edit Fact Modal --}}
    @if($editingFactId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="cancelEdit">
            <div class="w-full max-w-lg rounded-xl border border-gray-200 bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-gray-900">Edit Fact</h3>
                    <button wire:click="cancelEdit" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Source entity name</label>
                            <input wire:model="editSourceName" type="text"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
                            @error('editSourceName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Source entity type</label>
                            <select wire:model="editSourceType"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                                @foreach($entityTypes as $et)
                                    <option value="{{ $et->value }}">{{ $et->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Relation type</label>
                        <input wire:model="editRelationType" type="text"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
                        @error('editRelationType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Target entity name</label>
                            <input wire:model="editTargetName" type="text"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
                            @error('editTargetName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Target entity type</label>
                            <select wire:model="editTargetType"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                                @foreach($entityTypes as $et)
                                    <option value="{{ $et->value }}">{{ $et->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Fact statement</label>
                        <textarea wire:model="editFact" rows="3"
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                        @error('editFact') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button wire:click="cancelEdit"
                            class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button wire:click="updateFact"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">Save Changes</button>
                </div>
            </div>
        </div>
    @endif
</div>
