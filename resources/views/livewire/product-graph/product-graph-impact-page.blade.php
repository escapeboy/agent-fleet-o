<div class="space-y-6">
    @if ($disabled)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-6 text-amber-800">
            <h2 class="text-lg font-semibold">Product Graph is disabled</h2>
            <p class="mt-1 text-sm">Set <code>PRODUCT_GRAPH_ENABLED=true</code> to enable.</p>
        </div>
    @else
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Blast Radius</h1>
                <p class="text-sm text-gray-500">What is affected if a node changes.</p>
            </div>
            <a href="{{ route('product-graph.index') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">← Back to graph</a>
        </div>

        <div class="max-w-md">
            <select wire:model.live="nodeId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">Select a node…</option>
                @foreach ($nodes as $n)
                    <option value="{{ $n->id }}">{{ $n->name }} ({{ $n->node_type->label() }})</option>
                @endforeach
            </select>
        </div>

        @if ($selected)
            <div class="rounded-lg border border-gray-200 bg-white p-5">
                <p class="text-sm text-gray-500">If <span class="font-semibold text-gray-900">{{ $selected->name }}</span> changes, the following are potentially affected:</p>

                @if ($impact->isEmpty())
                    <p class="mt-4 text-sm text-gray-400">Nothing depends on this node — changing it is low-risk.</p>
                @else
                    <div class="mt-4 space-y-4">
                        @foreach ($impact as $depth => $rows)
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase text-gray-400">Depth {{ $depth }} — {{ count($rows) }} affected</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($rows as $row)
                                        <span class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm text-gray-700">
                                            {{ $row['name'] ?? '—' }}
                                            <span class="text-xs text-gray-400">via {{ str_replace('_', ' ', $row['via_edge_type']) }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
