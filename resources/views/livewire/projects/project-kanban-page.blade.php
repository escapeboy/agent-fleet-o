<div wire:poll.10s>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('projects.show', $project) }}" class="text-sm text-gray-500 hover:text-gray-700">
                <i class="fa-solid fa-chevron-left text-base"></i>
            </a>
            <h2 class="text-lg font-semibold">{{ $project->name }}</h2>
            <x-status-badge :status="$project->status->value" />
        </div>

        <div class="flex items-center gap-2">
            {{-- View toggle --}}
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5">
                <button wire:click="$set('viewMode', 'kanban')"
                    class="rounded-md px-3 py-1 text-xs font-medium transition {{ $viewMode === 'kanban' ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-900' }}">
                    Board
                </button>
                <button wire:click="$set('viewMode', 'graph')"
                    class="rounded-md px-3 py-1 text-xs font-medium transition {{ $viewMode === 'graph' ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-900' }}">
                    Graph
                </button>
            </div>
        </div>
    </div>

    @if($viewMode === 'kanban')
        {{-- Kanban Board --}}
        <div class="flex gap-4 overflow-x-auto pb-4">
            @foreach($columns as $key => $config)
                @php
                    $colorMap = [
                        'gray' => 'bg-gray-100 text-gray-700',
                        'yellow' => 'bg-yellow-100 text-yellow-700',
                        'blue' => 'bg-blue-100 text-blue-700',
                        'green' => 'bg-green-100 text-green-700',
                        'red' => 'bg-red-100 text-red-700',
                    ];
                    $headerColor = $colorMap[$config['color']] ?? 'bg-gray-100 text-gray-700';
                    $columnExperiments = $experimentsByColumn[$key] ?? collect();
                @endphp

                <div class="flex w-72 min-w-[18rem] flex-col rounded-lg bg-gray-50 border border-gray-200">
                    {{-- Column Header --}}
                    <div class="flex items-center justify-between px-3 py-2 border-b border-gray-200">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $headerColor }}">
                                {{ $config['label'] }}
                            </span>
                            <span class="text-xs text-gray-400">{{ $columnExperiments->count() }}</span>
                        </div>
                    </div>

                    {{-- Cards --}}
                    <div class="flex-1 space-y-2 overflow-y-auto p-2" style="max-height: 70vh;">
                        @forelse($columnExperiments as $experiment)
                            <a href="{{ route('experiments.show', $experiment) }}"
                                class="block rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition hover:shadow-md hover:border-gray-300">
                                <div class="flex items-start justify-between gap-2">
                                    <h4 class="text-sm font-medium text-gray-900 line-clamp-2">
                                        {{ $experiment->title ?? 'Untitled Experiment' }}
                                    </h4>
                                    <x-agent-status-indicator :status="$experiment->status->value" size="sm" />
                                </div>

                                @if($experiment->thesis)
                                    <p class="mt-1 text-xs text-gray-500 line-clamp-2">{{ $experiment->thesis }}</p>
                                @endif

                                <div class="mt-2 flex items-center justify-between">
                                    <span class="text-xs text-gray-400">
                                        Iter {{ $experiment->current_iteration }}/{{ $experiment->max_iterations }}
                                    </span>
                                    <span class="text-xs text-gray-400">{{ $experiment->created_at->diffForHumans() }}</span>
                                </div>

                                @if($experiment->budget_cap_credits)
                                    @php $pct = min(100, round(($experiment->budget_spent_credits / $experiment->budget_cap_credits) * 100)); @endphp
                                    <div class="mt-2 h-1 w-full rounded-full bg-gray-200">
                                        <div class="h-1 rounded-full {{ $pct > 80 ? 'bg-red-500' : ($pct > 50 ? 'bg-yellow-500' : 'bg-green-500') }}"
                                             style="width: {{ $pct }}%"></div>
                                    </div>
                                @endif
                            </a>
                        @empty
                            <div class="rounded-lg border border-dashed border-gray-300 p-4 text-center">
                                <p class="text-xs text-gray-400">No experiments</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @else
        {{-- Dependency Graph --}}
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            @if(empty($graphData['nodes']))
                <div class="py-12 text-center">
                    <p class="text-sm text-gray-400">No experiments to graph</p>
                </div>
            @else
                <div x-data="{
                    nodes: {{ Js::from($graphData['nodes']) }},
                    edges: {{ Js::from($graphData['edges']) }},
                    statusColors: {
                        'draft': '#9ca3af', 'signal_detected': '#9ca3af',
                        'scoring': '#eab308', 'planning': '#eab308', 'awaiting_approval': '#eab308',
                        'building': '#3b82f6', 'executing': '#3b82f6', 'collecting_metrics': '#3b82f6',
                        'evaluating': '#3b82f6', 'iterating': '#3b82f6',
                        'completed': '#22c55e', 'approved': '#22c55e',
                        'scoring_failed': '#ef4444', 'planning_failed': '#ef4444',
                        'building_failed': '#ef4444', 'execution_failed': '#ef4444',
                        'killed': '#ef4444', 'paused': '#f59e0b',
                    }
                }" class="min-h-[400px]">
                    {{-- Simple node graph rendered with SVG --}}
                    <svg class="w-full" :style="'height: ' + Math.max(400, nodes.length * 80) + 'px'" viewBox="0 0 800 600" preserveAspectRatio="xMidYMid meet">
                        {{-- Edges --}}
                        <template x-for="edge in edges" :key="edge.from + '-' + edge.to">
                            <line
                                :x1="(nodes.findIndex(n => n.id === edge.from) % 4) * 200 + 100"
                                :y1="Math.floor(nodes.findIndex(n => n.id === edge.from) / 4) * 80 + 30"
                                :x2="(nodes.findIndex(n => n.id === edge.to) % 4) * 200 + 100"
                                :y2="Math.floor(nodes.findIndex(n => n.id === edge.to) / 4) * 80 + 30"
                                stroke="#d1d5db" stroke-width="2" marker-end="url(#arrow)"
                            />
                        </template>

                        {{-- Arrow marker --}}
                        <defs>
                            <marker id="arrow" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                <path d="M 0 0 L 10 5 L 0 10 z" fill="#9ca3af"/>
                            </marker>
                        </defs>

                        {{-- Nodes --}}
                        <template x-for="(node, idx) in nodes" :key="node.id">
                            <g :transform="'translate(' + ((idx % 4) * 200 + 20) + ',' + (Math.floor(idx / 4) * 80 + 10) + ')'">
                                <rect width="160" height="40" rx="8" :fill="statusColors[node.status] || '#9ca3af'" opacity="0.15" stroke-width="2" :stroke="statusColors[node.status] || '#9ca3af'"/>
                                <text x="80" y="18" text-anchor="middle" font-size="11" font-weight="600" :fill="statusColors[node.status] || '#374151'" x-text="node.label.substring(0, 22)"></text>
                                <text x="80" y="32" text-anchor="middle" font-size="9" fill="#6b7280" x-text="node.status.replace('_', ' ') + ' · #' + node.iteration"></text>
                            </g>
                        </template>
                    </svg>
                </div>
            @endif
        </div>
    @endif
</div>
