<div class="space-y-6">
    @if ($disabled)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-6 text-amber-800">
            <h2 class="text-lg font-semibold">Product Graph is disabled</h2>
            <p class="mt-1 text-sm">Set <code>PRODUCT_GRAPH_ENABLED=true</code> to enable.</p>
        </div>
    @else
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Review Queue</h1>
                <p class="text-sm text-gray-500">Agents propose. Humans decide.</p>
            </div>
            <a href="{{ route('product-graph.index') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">← Back to graph</a>
        </div>

        @if ($success)
            <div class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ $success }}</div>
        @endif
        @if ($error)
            <div class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{{ $error }}</div>
        @endif

        <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
            <option value="pending">Pending</option>
            <option value="applied">Applied</option>
            <option value="rejected">Rejected</option>
            <option value="">All</option>
        </select>

        <div class="space-y-2">
            @forelse ($changes as $change)
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="rounded px-2 py-0.5 text-xs font-medium {{ $change->status->color() }}">{{ $change->status->label() }}</span>
                                <span class="font-medium text-gray-900">{{ $change->change_type->label() }}</span>
                                <span class="text-xs text-gray-400">by {{ $change->proposed_by_label }}</span>
                            </div>
                            <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-2 text-xs text-gray-600">{{ json_encode($change->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            @if ($change->review_note)
                                <p class="mt-1 text-xs text-gray-500">Note: {{ $change->review_note }}</p>
                            @endif
                        </div>
                        @if ($change->status === \App\Domain\ProductGraph\Enums\ChangeStatus::Pending)
                            @can('edit-content')
                                <div class="flex flex-shrink-0 gap-2">
                                    <button wire:click="approve('{{ $change->id }}')"
                                            class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Approve</button>
                                    <button wire:click="reject('{{ $change->id }}')"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Reject</button>
                                </div>
                            @endcan
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No proposals.</p>
            @endforelse
        </div>
    @endif
</div>
