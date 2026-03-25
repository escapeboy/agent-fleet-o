<div>
    {{-- Header Actions --}}
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <a href="{{ route('workflows.index') }}" class="shrink-0 text-gray-500 hover:text-gray-700">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            </a>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-900">{{ $workflow->name }}</h2>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $workflow->status->color() }}-100 text-{{ $workflow->status->color() }}-800">
                        {{ $workflow->status->label() }}
                    </span>
                    <span class="text-xs text-gray-400">v{{ $workflow->version }}</span>
                </div>
                @if($workflow->description)
                    <p class="mt-1 text-sm text-gray-500">{{ $workflow->description }}</p>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="recalculateCost" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Recalculate Cost
            </button>
            <button wire:click="duplicate" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Duplicate
            </button>
            @if($workflow->isActive())
                <a href="{{ route('workflows.schedule', $workflow) }}" class="rounded-lg border border-blue-300 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-100">
                    Schedule
                </a>
            @endif
            <a href="{{ route('workflows.edit', $workflow) }}" class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                Edit
            </a>
            <button wire:click="archive" wire:confirm="Are you sure you want to archive this workflow?"
                    class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">
                Archive
            </button>
            <x-send-to-assistant-button
                :message="'Tell me about workflow: ' . $workflow->name . '. Status: ' . $workflow->status->label() . ($workflow->description ? '. ' . $workflow->description : '')"
            />
        </div>
    </div>

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Left: Workflow info --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Stats cards --}}
            <div class="grid grid-cols-4 gap-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-bold text-gray-900">{{ $workflow->nodeCount() }}</div>
                    <div class="text-xs text-gray-500">Total Nodes</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-bold text-gray-900">{{ $agentNodes->count() }}</div>
                    <div class="text-xs text-gray-500">Agent Nodes</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-bold text-gray-900">{{ $workflow->edges->count() }}</div>
                    <div class="text-xs text-gray-500">Connections</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-bold text-gray-900">
                        {{ $workflow->estimated_cost_credits ? number_format($workflow->estimated_cost_credits) : '-' }}
                    </div>
                    <div class="text-xs text-gray-500">Est. Credits</div>
                </div>
            </div>

            {{-- Graph + Node list tabs --}}
            <div class="rounded-xl border border-gray-200 bg-white" x-data="{ tab: 'graph' }" wire:poll.{{ $hasRunningSteps ? '3' : '15' }}s>
                <div class="flex items-center border-b border-gray-200 px-4">
                    <button @click="tab = 'graph'"
                            class="border-b-2 px-3 py-3 text-sm font-medium transition"
                            :class="tab === 'graph' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'">
                        Graph
                    </button>
                    <button @click="tab = 'list'"
                            class="border-b-2 px-3 py-3 text-sm font-medium transition"
                            :class="tab === 'list' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'">
                        Nodes
                    </button>
                    @if($activeExperiment)
                        <span class="ml-auto flex items-center gap-1.5 text-xs text-gray-400 pr-1">
                            @if($hasRunningSteps)
                                <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-blue-500"></span>
                                Running
                            @else
                                Showing: <a href="{{ route('experiments.show', $activeExperiment) }}" class="text-primary-600 hover:underline">{{ Str::limit($activeExperiment->title, 24) }}</a>
                            @endif
                        </span>
                    @endif
                </div>

                {{-- Graph canvas --}}
                <div x-show="tab === 'graph'">
                    @php
                        $graphNodes = $workflow->nodes->map(fn ($n) => [
                            'id'         => $n->id,
                            'type'       => $n->type->value,
                            'label'      => $n->label,
                            'position_x' => $n->position_x,
                            'position_y' => $n->position_y,
                            'agent_name' => $n->agent?->name,
                        ])->values()->toArray();

                        $graphEdges = $workflow->edges->map(fn ($e) => [
                            'id'             => $e->id,
                            'source_node_id' => $e->source_node_id,
                            'target_node_id' => $e->target_node_id,
                        ])->values()->toArray();
                    @endphp

                    <div class="relative h-96 overflow-hidden bg-gray-50"
                         x-data="workflowGraphView(@js($graphNodes), @js($graphEdges), @js($stepStatuses))">

                        {{-- Grid --}}
                        <svg class="absolute inset-0 h-full w-full pointer-events-none">
                            <defs>
                                <pattern id="grid-view" width="20" height="20" patternUnits="userSpaceOnUse">
                                    <path d="M 20 0 L 0 0 0 20" fill="none" stroke="#e5e7eb" stroke-width="0.5"/>
                                </pattern>
                            </defs>
                            <rect width="5000" height="5000" x="-2500" y="-2500" fill="url(#grid-view)" />
                            <g :transform="`translate(${panX} ${panY}) scale(${zoom})`">
                                <g x-html="renderEdges()"></g>
                            </g>
                        </svg>

                        {{-- Nodes --}}
                        <div class="absolute inset-0"
                             :style="'transform: translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + '); transform-origin: 0 0;'">
                            <template x-for="node in nodes" :key="node.id">
                                <div class="absolute select-none"
                                     :style="'left:' + node.position_x + 'px; top:' + node.position_y + 'px; min-width:140px;'">
                                    <div class="rounded-lg border-2 bg-white shadow-sm"
                                         :class="nodeBorderClass(node)">
                                        {{-- Type header --}}
                                        <div class="flex items-center gap-1.5 rounded-t-md px-2.5 py-1.5"
                                             :class="nodeHeaderClass(node)">
                                            <span class="text-[10px] font-semibold uppercase tracking-wide" :class="nodeTextClass(node)" x-text="node.type"></span>
                                            {{-- Status badge --}}
                                            <span x-show="node.step_status && node.step_status !== 'pending'"
                                                  class="ml-auto inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[9px] font-semibold"
                                                  :class="statusBadgeClass(node.step_status)">
                                                <span x-show="node.step_status === 'running'" class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-current"></span>
                                                <span x-text="node.step_status"></span>
                                            </span>
                                        </div>
                                        {{-- Label --}}
                                        <div class="px-2.5 py-1.5">
                                            <div class="text-xs font-medium text-gray-700" x-text="node.label"></div>
                                            <div x-show="node.agent_name" class="text-[10px] text-gray-400" x-text="node.agent_name"></div>
                                            <div x-show="node.duration_ms" class="text-[10px] text-gray-400" x-text="node.duration_ms ? Math.round(node.duration_ms/1000) + 's' : ''"></div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Zoom controls --}}
                        <div class="absolute bottom-2 right-2 flex items-center gap-0.5 rounded-lg bg-white border border-gray-200 shadow-sm px-0.5">
                            <button @click="zoom = Math.max(0.3, zoom - 0.15)" class="p-1 text-gray-500 hover:text-gray-700">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                            </button>
                            <span class="text-[10px] text-gray-500 w-8 text-center" x-text="Math.round(zoom*100)+'%'"></span>
                            <button @click="zoom = Math.min(1.5, zoom + 0.15)" class="p-1 text-gray-500 hover:text-gray-700">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            </button>
                            <button @click="fitView()" class="p-1 text-gray-500 hover:text-gray-700 border-l border-gray-200 ml-0.5">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Node list --}}
                <div x-show="tab === 'list'" class="divide-y divide-gray-100">
                    @foreach($workflow->nodes->sortBy('order') as $node)
                        <div class="flex items-center gap-3 px-4 py-3">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg
                                {{ match($node->type->value) {
                                    'start' => 'bg-green-100 text-green-600',
                                    'end' => 'bg-red-100 text-red-600',
                                    'agent' => 'bg-purple-100 text-purple-600',
                                    'conditional' => 'bg-yellow-100 text-yellow-600',
                                    default => 'bg-gray-100 text-gray-600',
                                } }}">
                                <span class="text-xs font-medium">{{ strtoupper(substr($node->type->value, 0, 1)) }}</span>
                            </span>
                            <div class="flex-1">
                                <div class="text-sm font-medium text-gray-700">{{ $node->label }}</div>
                                @if($node->agent)
                                    <div class="text-xs text-gray-400">Agent: {{ $node->agent->name }}</div>
                                @endif
                                @if($node->skill)
                                    <div class="text-xs text-gray-400">Skill: {{ $node->skill->name }}</div>
                                @endif
                            </div>
                            @if(isset($stepStatuses[$node->id]))
                                @php $ss = $stepStatuses[$node->id]; @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ match($ss['status']) {
                                        'completed' => 'bg-green-100 text-green-700',
                                        'running'   => 'bg-blue-100 text-blue-700',
                                        'failed'    => 'bg-red-100 text-red-700',
                                        'skipped'   => 'bg-gray-100 text-gray-500',
                                        default     => 'bg-gray-100 text-gray-500',
                                    } }}">
                                    {{ $ss['status'] }}
                                </span>
                            @else
                                <span class="text-xs text-gray-400">{{ $node->type->label() }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Recent experiments --}}
            <div class="rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Recent Experiments</h3>
                </div>
                @if($experiments->isEmpty())
                    <div class="px-4 py-8 text-center text-sm text-gray-400">
                        No experiments have used this workflow yet.
                    </div>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach($experiments as $exp)
                            <div class="flex items-center gap-3 px-4 py-3">
                                <div class="flex-1">
                                    <a href="{{ route('experiments.show', $exp) }}" class="text-sm font-medium text-primary-600 hover:text-primary-800">
                                        {{ $exp->title }}
                                    </a>
                                </div>
                                <x-status-badge :status="$exp->status->value" />
                                <span class="text-xs text-gray-400">{{ $exp->created_at->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Right: Metadata --}}
        <div class="space-y-6">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Details</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Created by</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->user?->name ?? 'System' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Version</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->version }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Max Loop Iterations</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->max_loop_iterations }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Created</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->created_at->format('M j, Y H:i') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Updated</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->updated_at->diffForHumans() }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>

    {{-- Plugin extension point: inject custom content into workflow detail --}}
    @stack('fleet.workflow.detail')
</div>

@script
<script>
Alpine.data('workflowGraphView', (nodes, edges, stepStatuses) => ({
    nodes: nodes.map(n => ({
        ...n,
        step_status:   stepStatuses[n.id]?.status   ?? null,
        duration_ms:   stepStatuses[n.id]?.duration  ?? null,
    })),
    edges,
    zoom: 0.7,
    panX: 20,
    panY: 20,

    init() { this.$nextTick(() => this.fitView()); },

    fitView() {
        if (!this.nodes.length) return;
        const xs = this.nodes.map(n => n.position_x);
        const ys = this.nodes.map(n => n.position_y);
        const minX = Math.min(...xs), maxX = Math.max(...xs) + 160;
        const minY = Math.min(...ys), maxY = Math.max(...ys) + 80;
        const container = this.$el;
        const cw = container.clientWidth  || 600;
        const ch = container.clientHeight || 384;
        const z = Math.min((cw - 40) / (maxX - minX || 1), (ch - 40) / (maxY - minY || 1), 1);
        this.zoom = Math.max(0.3, Math.min(z, 1.2));
        this.panX = (cw - (maxX - minX) * this.zoom) / 2 - minX * this.zoom;
        this.panY = (ch - (maxY - minY) * this.zoom) / 2 - minY * this.zoom;
    },

    nodeById(id) { return this.nodes.find(n => n.id === id); },

    renderEdges() {
        return this.edges.map(edge => {
            const src = this.nodeById(edge.source_node_id);
            const tgt = this.nodeById(edge.target_node_id);
            if (!src || !tgt) return '';
            const x1 = src.position_x + 70;
            const y1 = src.position_y + 60;
            const x2 = tgt.position_x + 70;
            const y2 = tgt.position_y;
            const cy = (y1 + y2) / 2;
            return `<path d="M ${x1} ${y1} C ${x1} ${cy}, ${x2} ${cy}, ${x2} ${y2}"
                         fill="none" stroke="#9ca3af" stroke-width="1.5"
                         marker-end="url(#arrow-view)" />`;
        }).join('') + `<defs><marker id="arrow-view" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto">
            <path d="M0,0 L0,6 L6,3 z" fill="#9ca3af"/>
        </marker></defs>`;
    },

    nodeBorderClass(node) {
        const status = node.step_status;
        if (status === 'running')   return 'border-blue-500 shadow-blue-100 shadow-md';
        if (status === 'completed') return 'border-green-500';
        if (status === 'failed')    return 'border-red-500';
        if (status === 'skipped')   return 'border-gray-300';
        const typeMap = {
            start: 'border-green-400', end: 'border-red-400', agent: 'border-purple-400',
            crew: 'border-teal-400', conditional: 'border-yellow-400', human_task: 'border-indigo-400',
            switch: 'border-orange-400', do_while: 'border-cyan-400', sub_workflow: 'border-violet-400',
        };
        return typeMap[node.type] ?? 'border-gray-300';
    },

    nodeHeaderClass(node) {
        const status = node.step_status;
        if (status === 'running')   return 'bg-blue-50';
        if (status === 'completed') return 'bg-green-50';
        if (status === 'failed')    return 'bg-red-50';
        const typeMap = {
            start: 'bg-green-50', end: 'bg-red-50', agent: 'bg-purple-50', crew: 'bg-teal-50',
            conditional: 'bg-yellow-50', human_task: 'bg-indigo-50', switch: 'bg-orange-50',
            do_while: 'bg-cyan-50', sub_workflow: 'bg-violet-50',
        };
        return typeMap[node.type] ?? 'bg-gray-50';
    },

    nodeTextClass(node) {
        const typeMap = {
            start: 'text-green-700', end: 'text-red-700', agent: 'text-purple-700', crew: 'text-teal-700',
            conditional: 'text-yellow-700', human_task: 'text-indigo-700', switch: 'text-orange-700',
            do_while: 'text-cyan-700', sub_workflow: 'text-violet-700',
        };
        return typeMap[node.type] ?? 'text-gray-700';
    },

    statusBadgeClass(status) {
        return {
            running:   'bg-blue-100 text-blue-700',
            completed: 'bg-green-100 text-green-700',
            failed:    'bg-red-100 text-red-700',
            skipped:   'bg-gray-100 text-gray-500',
        }[status] ?? 'bg-gray-100 text-gray-500';
    },
}));
</script>
@endscript
