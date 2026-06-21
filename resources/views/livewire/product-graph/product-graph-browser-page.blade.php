<div class="space-y-6">
    @if ($disabled)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-6 text-amber-800">
            <h2 class="text-lg font-semibold">Product Graph is disabled</h2>
            <p class="mt-1 text-sm">Set <code>PRODUCT_GRAPH_ENABLED=true</code> to enable the product graph.</p>
        </div>
    @else
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Product Graph</h1>
                <p class="text-sm text-gray-500">The map of what you're building and how it connects.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('product-graph.changes') }}"
                   class="relative rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Review queue
                    @if ($pendingCount > 0)
                        <span class="ml-1 rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white">{{ $pendingCount }}</span>
                    @endif
                </a>
                <a href="{{ route('product-graph.impact') }}"
                   class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Blast radius</a>
                @can('edit-content')
                    <button wire:click="importInventory" wire:loading.attr="disabled"
                            class="rounded-lg border border-violet-300 bg-violet-50 px-3 py-1.5 text-sm font-medium text-violet-700 hover:bg-violet-100">
                        Import from inventory
                    </button>
                    <button wire:click="$toggle('showAddNode')"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">Add node</button>
                @endcan
            </div>
        </div>

        @if ($success)
            <div class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ $success }}</div>
        @endif
        @if ($error)
            <div class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{{ $error }}</div>
        @endif

        @can('edit-content')
            @if ($showAddNode)
                <div class="grid gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 md:grid-cols-2">
                    <x-form-input wire:model="newName" label="Name" />
                    <x-form-select wire:model="newNodeType" label="Type">
                        @foreach ($nodeTypes as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </x-form-select>
                    <x-form-select wire:model="newStatus" label="Status">
                        @foreach ($statuses as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </x-form-select>
                    <x-form-input wire:model="newTags" label="Tags (comma-separated)" />
                    <div class="md:col-span-2">
                        <x-form-textarea wire:model="newDescription" label="Description" />
                    </div>
                    <div class="md:col-span-2 flex gap-2">
                        <button wire:click="addNode" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">Save node</button>
                        <button wire:click="$set('showAddNode', false)" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">Cancel</button>
                        <button wire:click="$toggle('showAddEdge')" class="ml-auto rounded-lg border border-gray-300 px-4 py-2 text-sm">Add edge…</button>
                    </div>
                    @if ($showAddEdge)
                        <div class="md:col-span-2 grid gap-3 rounded-lg border border-gray-200 bg-white p-3 md:grid-cols-3">
                            <x-form-select wire:model="edgeSource" label="Source">
                                <option value="">—</option>
                                @foreach ($allNodes as $n)
                                    <option value="{{ $n->id }}">{{ $n->name }}</option>
                                @endforeach
                            </x-form-select>
                            <x-form-select wire:model="edgeType" label="Relationship">
                                @foreach ($edgeTypes as $e)
                                    <option value="{{ $e->value }}">{{ $e->label() }}</option>
                                @endforeach
                            </x-form-select>
                            <x-form-select wire:model="edgeTarget" label="Target">
                                <option value="">—</option>
                                @foreach ($allNodes as $n)
                                    <option value="{{ $n->id }}">{{ $n->name }}</option>
                                @endforeach
                            </x-form-select>
                            <div class="md:col-span-3">
                                <button wire:click="addEdge" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">Save edge</button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        @endcan

        {{-- Filters + tabs --}}
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg border border-gray-200 p-0.5 text-sm">
                @foreach (['map' => 'Map', 'list' => 'List', 'releases' => 'Releases'] as $key => $label)
                    <button wire:click="$set('tab', '{{ $key }}')"
                            class="rounded-md px-3 py-1 {{ $tab === $key ? 'bg-primary-600 text-white' : 'text-gray-600' }}">{{ $label }}</button>
                @endforeach
            </div>
            <input wire:model.live.debounce.300ms="search" placeholder="Search name…"
                   class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" />
            <select wire:model.live="nodeTypeFilter" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">All types</option>
                @foreach ($nodeTypes as $t)
                    <option value="{{ $t->value }}">{{ $t->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid gap-4 {{ $selected ? 'lg:grid-cols-3' : '' }}">
            <div class="{{ $selected ? 'lg:col-span-2' : '' }}">
                @if ($tab === 'map')
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white"
                         x-data="{ scale: 1, tx: 0, ty: 0, drag: false, sx: 0, sy: 0 }">
                        <div class="flex items-center gap-2 border-b border-gray-100 px-3 py-1.5 text-xs text-gray-500">
                            <button @click="scale = Math.min(2, scale + 0.1)" class="rounded border px-2">+</button>
                            <button @click="scale = Math.max(0.4, scale - 0.1)" class="rounded border px-2">−</button>
                            <button @click="scale = 1; tx = 0; ty = 0" class="rounded border px-2">reset</button>
                            <span>drag to pan · scroll node count: {{ count($graph['nodes']) }}</span>
                        </div>
                        <svg class="h-[60vh] w-full cursor-move select-none"
                             @mousedown="drag = true; sx = $event.clientX - tx; sy = $event.clientY - ty"
                             @mousemove="drag && (tx = $event.clientX - sx, ty = $event.clientY - sy)"
                             @mouseup="drag = false" @mouseleave="drag = false">
                            <g :transform="`translate(${tx},${ty}) scale(${scale})`">
                                @foreach ($graph['edges'] as $e)
                                    <line x1="{{ $e['x1'] }}" y1="{{ $e['y1'] }}" x2="{{ $e['x2'] }}" y2="{{ $e['y2'] }}"
                                          stroke="#cbd5e1" stroke-width="1" />
                                @endforeach
                                @foreach ($graph['nodes'] as $n)
                                    <g wire:click="selectNode('{{ $n['id'] }}')" class="cursor-pointer">
                                        <rect x="{{ $n['x'] - 60 }}" y="{{ $n['y'] - 14 }}" width="120" height="28" rx="6"
                                              fill="{{ $selected && $selected->id === $n['id'] ? '#4f46e5' : '#fff' }}"
                                              stroke="#94a3b8" stroke-width="1" />
                                        <text x="{{ $n['x'] }}" y="{{ $n['y'] + 4 }}" text-anchor="middle"
                                              font-size="10" fill="{{ $selected && $selected->id === $n['id'] ? '#fff' : '#334155' }}">
                                            {{ \Illuminate\Support\Str::limit($n['name'], 18) }}
                                        </text>
                                    </g>
                                @endforeach
                            </g>
                        </svg>
                    </div>
                @elseif ($tab === 'releases')
                    <div class="space-y-2">
                        @forelse ($releases as $r)
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2">
                                <div>
                                    <span class="font-medium text-gray-900">{{ $r->name }}</span>
                                    <span class="ml-2 rounded px-2 py-0.5 text-xs {{ $r->status->color() }}">{{ $r->status->label() }}</span>
                                </div>
                                <span class="text-xs text-gray-400">{{ $r->created_at?->format('Y-m-d') }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">No release nodes yet.</p>
                        @endforelse
                    </div>
                @else
                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-4 py-2">Name</th>
                                    <th class="px-4 py-2">Type</th>
                                    <th class="px-4 py-2">Status</th>
                                    <th class="px-4 py-2">Edges</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse ($nodes as $n)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2">
                                            <button wire:click="selectNode('{{ $n->id }}')" class="font-medium text-primary-700 hover:underline">{{ $n->name }}</button>
                                        </td>
                                        <td class="px-4 py-2 text-gray-600">{{ $n->node_type->label() }}</td>
                                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-xs {{ $n->status->color() }}">{{ $n->status->label() }}</span></td>
                                        <td class="px-4 py-2 text-gray-500">{{ $n->outgoing_edges_count }}↑ / {{ $n->incoming_edges_count }}↓</td>
                                        <td class="px-4 py-2 text-right">
                                            @can('edit-content')
                                                <button wire:click="deleteNode('{{ $n->id }}')" wire:confirm="Delete this node and its edges?" class="text-xs text-red-600 hover:underline">delete</button>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No nodes. Add one or import from inventory.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            @if ($selected)
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ $selected->name }}</h3>
                            <p class="text-xs text-gray-500">{{ $selected->node_type->label() }} · <span class="rounded px-1.5 py-0.5 {{ $selected->status->color() }}">{{ $selected->status->label() }}</span></p>
                        </div>
                        <button wire:click="closeDrawer" class="text-gray-400 hover:text-gray-600">✕</button>
                    </div>
                    @if ($selected->description)
                        <p class="mt-2 text-sm text-gray-600">{{ $selected->description }}</p>
                    @endif
                    @if (! empty($selected->tags))
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($selected->tags as $tag)
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                    <a href="{{ route('product-graph.impact', ['nodeId' => $selected->id]) }}"
                       class="mt-3 inline-block text-xs font-medium text-primary-700 hover:underline">View blast radius →</a>

                    <div class="mt-4 space-y-3 text-sm">
                        <div>
                            <p class="text-xs font-semibold uppercase text-gray-400">Depends on / connects to</p>
                            @forelse ($selectedEdges['out'] as $e)
                                <p class="text-gray-700">{{ $e->edge_type->label() }} → <span class="font-medium">{{ $e->target?->name }}</span></p>
                            @empty
                                <p class="text-gray-400">—</p>
                            @endforelse
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-gray-400">Depended on by</p>
                            @forelse ($selectedEdges['in'] as $e)
                                <p class="text-gray-700"><span class="font-medium">{{ $e->source?->name }}</span> {{ $e->edge_type->label() }} this</p>
                            @empty
                                <p class="text-gray-400">—</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
