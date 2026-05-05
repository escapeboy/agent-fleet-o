<div
    wire:poll.5s="refreshFeed"
    x-data="teamGraph(@js($graph), @entangle('selectedNodeId'))"
    x-init="init()"
    class="flex h-[calc(100vh-7rem)] gap-4"
>
    {{-- Graph canvas --}}
    <div class="relative flex-1 rounded-xl border border-gray-200 bg-white">
        <div class="absolute left-3 top-3 z-10 flex items-center gap-2 text-xs text-gray-500">
            <span class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-0.5 text-blue-700">
                <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span> Agent
            </span>
            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-emerald-700">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Human
            </span>
            <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-amber-700">
                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> Crew
            </span>
            <span class="ml-2 text-gray-400" x-text="liveStatusLabel"></span>
        </div>
        <div wire:ignore id="team-graph-canvas" class="h-full w-full rounded-xl"></div>

        @if(empty($graph['nodes']))
            <div class="absolute inset-0 flex flex-col items-center justify-center text-center text-gray-500">
                <i class="fa-solid fa-sitemap mb-3 text-4xl text-gray-300"></i>
                <p class="text-sm font-medium">No team members or agents yet</p>
                <p class="mt-1 text-xs text-gray-400">Create an agent or invite a teammate to see your team graph come alive.</p>
            </div>
        @endif
    </div>

    {{-- Right sidebar: drawer + activity firehose --}}
    <div class="flex w-96 shrink-0 flex-col gap-4">
        {{-- Drawer --}}
        @if($selectedNodeId)
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">{{ $drawerLabel ?? '—' }}</h3>
                    <button wire:click="closeDrawer" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                @if(empty($drawerActivity))
                    <p class="text-xs text-gray-500">No recent activity.</p>
                @else
                    <ul class="space-y-2 text-xs text-gray-600">
                        @foreach($drawerActivity as $act)
                            <li class="flex items-start gap-2 border-l-2 border-gray-200 pl-2">
                                <span class="font-mono text-[10px] text-gray-400">{{ $act['at'] ?? '—' }}</span>
                                <span class="flex-1">
                                    <span class="font-medium text-gray-700">{{ $act['status'] ?? 'event' }}</span>
                                    @isset($act['duration_ms'])
                                        <span class="ml-1 text-gray-400">{{ $act['duration_ms'] }}ms</span>
                                    @endisset
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- Activity firehose --}}
        <div class="flex flex-1 flex-col rounded-xl border border-gray-200 bg-white">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fa-solid fa-bolt text-amber-500"></i>
                    Activity
                </h3>
                <span class="text-[10px] uppercase tracking-wider text-gray-400" x-text="liveStatusLabel"></span>
            </div>
            <ul
                id="team-graph-feed"
                class="flex-1 divide-y divide-gray-100 overflow-y-auto"
            >
                @forelse($feed as $item)
                    <li class="px-4 py-2.5 text-xs">
                        <div class="flex items-center gap-2">
                            @if($item['actor_kind'] === 'agent')
                                <i class="fa-solid fa-microchip text-blue-500"></i>
                            @elseif($item['actor_kind'] === 'experiment')
                                <i class="fa-solid fa-flask text-purple-500"></i>
                            @else
                                <i class="fa-solid fa-circle-info text-gray-400"></i>
                            @endif
                            <span class="font-medium text-gray-700">{{ $item['actor_label'] ?? '—' }}</span>
                            <span class="ml-auto font-mono text-[10px] text-gray-400">
                                {{ $item['at'] ? \Carbon\Carbon::parse($item['at'])->diffForHumans() : '—' }}
                            </span>
                        </div>
                        <p class="mt-1 text-gray-500">{{ $item['summary'] ?? '' }}</p>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-xs text-gray-400">No recent activity.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://unpkg.com/cytoscape@3.30/dist/cytoscape.min.js"></script>
<script>
    function teamGraph(initialGraph, selectedNodeIdRef) {
        return {
            cy: null,
            liveStatusLabel: 'live',
            init() {
                this.liveStatusLabel = window.Echo ? 'live' : 'polling 5s';
                this.bootCytoscape(initialGraph);
                this.subscribeToActivity();
            },
            bootCytoscape(graph) {
                if (typeof cytoscape === 'undefined') {
                    setTimeout(() => this.bootCytoscape(graph), 100);
                    return;
                }
                // Guard against re-init from Alpine x-init re-runs (Livewire DOM morph)
                if (this.cy) {
                    return;
                }
                const container = document.getElementById('team-graph-canvas');
                if (!container) {
                    // Container not in DOM yet — retry once on next tick
                    setTimeout(() => this.bootCytoscape(graph), 50);
                    return;
                }
                const elements = [
                    ...graph.nodes.map(n => ({
                        data: { id: n.id, label: n.label, type: n.type, vendor: n.vendor || null, status: n.status || null, role: n.role || null, initials: n.initials || null },
                    })),
                    ...graph.edges.map(e => ({
                        data: { id: e.id, source: e.source, target: e.target, kind: e.kind, role: e.role || null },
                    })),
                ];
                this.cy = cytoscape({
                    container,
                    elements,
                    style: [
                        { selector: 'node[type="agent"]',
                          style: { 'background-color': '#3b82f6', 'label': 'data(label)', 'color': '#1f2937', 'font-size': 11, 'font-weight': 600, 'text-valign': 'bottom', 'text-margin-y': 6, 'width': 38, 'height': 38, 'border-width': 2, 'border-color': '#bfdbfe' } },
                        { selector: 'node[type="human"]',
                          style: { 'background-color': '#10b981', 'shape': 'ellipse', 'label': 'data(initials)', 'color': '#fff', 'text-valign': 'center', 'text-halign': 'center', 'font-size': 11, 'font-weight': 700, 'width': 36, 'height': 36, 'border-width': 2, 'border-color': '#a7f3d0' } },
                        { selector: 'node[type="crew"]',
                          style: { 'background-color': '#f59e0b', 'shape': 'hexagon', 'label': 'data(label)', 'color': '#1f2937', 'font-size': 11, 'font-weight': 600, 'text-valign': 'bottom', 'text-margin-y': 6, 'width': 44, 'height': 44, 'border-width': 2, 'border-color': '#fde68a' } },
                        { selector: 'edge',
                          style: { 'width': 1.5, 'line-color': '#cbd5e1', 'curve-style': 'bezier', 'target-arrow-shape': 'triangle', 'target-arrow-color': '#94a3b8', 'label': 'data(role)', 'font-size': 8, 'color': '#64748b', 'text-rotation': 'autorotate', 'text-margin-y': -4 } },
                        { selector: 'node:selected',
                          style: { 'border-width': 4, 'border-color': '#1d4ed8' } },
                        { selector: 'node.pulsing',
                          style: { 'border-width': 5, 'border-color': '#fbbf24' } },
                    ],
                    layout: { name: 'cose', animate: true, padding: 30, idealEdgeLength: 90, nodeRepulsion: 12000 },
                    wheelSensitivity: 0.2,
                });
                this.cy.on('tap', 'node', (evt) => {
                    @this.call('openDrawer', evt.target.id());
                });
            },
            subscribeToActivity() {
                if (!window.Echo) return;
                const teamId = '{{ auth()->user()->current_team_id }}';
                if (!teamId) return;
                try {
                    window.Echo.private(`team.${teamId}.activity`)
                        .listen('.team-activity', (e) => this.onActivity(e));
                } catch (err) {
                    console.warn('Echo subscription failed, falling back to polling:', err);
                    this.liveStatusLabel = 'polling 5s';
                }
            },
            onActivity(event) {
                this.prependFeed(event);
                this.pulseNode(event);
            },
            prependFeed(event) {
                const ul = document.getElementById('team-graph-feed');
                if (!ul) return;
                const li = document.createElement('li');
                li.className = 'px-4 py-2.5 text-xs bg-yellow-50 transition-colors';

                const headerRow = document.createElement('div');
                headerRow.className = 'flex items-center gap-2';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-bolt text-amber-500';
                const actor = document.createElement('span');
                actor.className = 'font-medium text-gray-700';
                actor.textContent = event.actor_label || '—';
                const ts = document.createElement('span');
                ts.className = 'ml-auto font-mono text-[10px] text-gray-400';
                ts.textContent = 'just now';
                headerRow.append(icon, actor, ts);

                const body = document.createElement('p');
                body.className = 'mt-1 text-gray-500';
                body.textContent = event.summary || '';

                li.append(headerRow, body);
                ul.prepend(li);
                setTimeout(() => { li.classList.remove('bg-yellow-50'); }, 2000);
                while (ul.children.length > 30) ul.removeChild(ul.lastChild);
            },
            pulseNode(event) {
                if (!this.cy || event.actor_kind !== 'agent' || !event.actor_id) return;
                const node = this.cy.getElementById('agent:' + event.actor_id);
                if (!node || node.empty()) return;
                node.addClass('pulsing');
                setTimeout(() => node.removeClass('pulsing'), 1500);
            },
        };
    }
    window.teamGraph = teamGraph;
</script>
@endpush
