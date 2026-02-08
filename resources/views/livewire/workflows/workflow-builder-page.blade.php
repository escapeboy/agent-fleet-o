<div x-data="workflowBuilder(@js($nodes), @js($edges), @js($availableAgents), @js($availableSkills))" class="flex flex-col h-[calc(100vh-8rem)]">
    {{-- Top Bar --}}
    <div class="flex items-center gap-4 border-b border-gray-200 bg-white px-4 py-3">
        <div class="flex-1 flex items-center gap-3">
            <x-form-input wire:model="name" type="text" placeholder="Workflow name..." compact />
            <x-form-input wire:model="maxLoopIterations" type="number" placeholder="Max loops" compact class="w-24" />
        </div>

        <div class="flex items-center gap-2">
            <button wire:click="save" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Save Draft
            </button>
            <button wire:click="validateGraph" class="rounded-lg border border-blue-300 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-100">
                Validate
            </button>
            <button wire:click="activate" class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                Activate
            </button>
        </div>
    </div>

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="mx-4 mt-2 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mx-4 mt-2 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Validation Errors --}}
    @if(count($validationErrors) > 0)
        <div class="mx-4 mt-2 rounded-lg bg-red-50 p-3">
            <h4 class="text-sm font-medium text-red-800">Validation Errors</h4>
            <ul class="mt-1 list-disc pl-5 text-sm text-red-700">
                @foreach($validationErrors as $err)
                    <li>{{ $err['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-1 overflow-hidden">
        {{-- Sidebar: Node palette --}}
        <div class="w-56 flex-shrink-0 overflow-y-auto border-r border-gray-200 bg-gray-50 p-3">
            <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Add Nodes</h3>

            <div class="space-y-2">
                <button @click="addNode('agent')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-primary-300 hover:bg-primary-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-purple-100 text-purple-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </span>
                    Agent Node
                </button>
                <button @click="addNode('conditional')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-yellow-300 hover:bg-yellow-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-yellow-100 text-yellow-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    </span>
                    Condition
                </button>
                <button @click="addNode('end')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-red-300 hover:bg-red-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-red-100 text-red-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/></svg>
                    </span>
                    End Node
                </button>
            </div>

            {{-- Description --}}
            <div class="mt-4">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Description</h3>
                <x-form-textarea wire:model="description" rows="3" placeholder="Workflow description..." compact />
            </div>

            {{-- Info --}}
            <div class="mt-4 rounded-lg bg-blue-50 p-3 text-xs text-blue-700">
                <p class="font-medium">How to use:</p>
                <ul class="mt-1 space-y-0.5 list-disc pl-4">
                    <li>Drag nodes to position</li>
                    <li>Click a node output port, then click a target input port to connect</li>
                    <li>Click a node to configure it</li>
                    <li>Press Delete to remove selected</li>
                </ul>
            </div>
        </div>

        {{-- Canvas --}}
        <div class="relative flex-1 overflow-hidden bg-gray-100"
             @mousedown.self="startPan($event)"
             @mousemove="onMouseMove($event)"
             @mouseup="onMouseUp($event)">

            <svg class="absolute inset-0 h-full w-full" :style="'transform: translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + ')'">
                {{-- Grid pattern --}}
                <defs>
                    <pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse">
                        <path d="M 20 0 L 0 0 0 20" fill="none" stroke="#e5e7eb" stroke-width="0.5"/>
                    </pattern>
                </defs>
                <rect width="5000" height="5000" x="-2500" y="-2500" fill="url(#grid)" />

                {{-- Edges --}}
                <template x-for="edge in localEdges" :key="edge.id">
                    <g @click.stop="selectEdge(edge.id)">
                        <path :d="getEdgePath(edge)" fill="none"
                              :stroke="selectedEdgeId === edge.id ? '#3b82f6' : (edge.is_default ? '#9ca3af' : '#6b7280')"
                              stroke-width="2" class="cursor-pointer hover:stroke-blue-400"
                              :stroke-dasharray="edge.is_default ? '5,5' : 'none'" />
                        <text x-show="edge.label" :x="getEdgeMidpoint(edge).x" :y="getEdgeMidpoint(edge).y - 8"
                              text-anchor="middle" class="text-xs fill-gray-500 pointer-events-none" x-text="edge.label"></text>
                    </g>
                </template>

                {{-- Connection line (while dragging) --}}
                <line x-show="isConnecting" :x1="connectFromX" :y1="connectFromY" :x2="connectToX" :y2="connectToY"
                      stroke="#3b82f6" stroke-width="2" stroke-dasharray="4,4" />
            </svg>

            {{-- Nodes --}}
            <div class="absolute inset-0" :style="'transform: translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + '); transform-origin: 0 0;'">
                <template x-for="node in localNodes" :key="node.id">
                    <div class="absolute cursor-move select-none"
                         :style="'left: ' + node.position_x + 'px; top: ' + node.position_y + 'px;'"
                         @mousedown.stop="startDragNode(node.id, $event)"
                         @click.stop="selectNode(node.id)">

                        {{-- Node card --}}
                        <div class="relative rounded-lg border-2 bg-white shadow-sm transition-shadow hover:shadow-md"
                             :class="{
                                'border-blue-500 shadow-blue-100': selectedNodeId === node.id,
                                'border-green-400': node.type === 'start',
                                'border-red-400': node.type === 'end',
                                'border-purple-400': node.type === 'agent' && selectedNodeId !== node.id,
                                'border-yellow-400': node.type === 'conditional' && selectedNodeId !== node.id,
                             }"
                             style="min-width: 160px;">

                            {{-- Header --}}
                            <div class="flex items-center gap-2 px-3 py-2 rounded-t-lg"
                                 :class="{
                                    'bg-green-50': node.type === 'start',
                                    'bg-red-50': node.type === 'end',
                                    'bg-purple-50': node.type === 'agent',
                                    'bg-yellow-50': node.type === 'conditional',
                                 }">
                                <span class="text-xs font-medium uppercase"
                                      :class="{
                                         'text-green-700': node.type === 'start',
                                         'text-red-700': node.type === 'end',
                                         'text-purple-700': node.type === 'agent',
                                         'text-yellow-700': node.type === 'conditional',
                                      }" x-text="node.type.charAt(0).toUpperCase() + node.type.slice(1)"></span>
                                <button x-show="node.type !== 'start'" @click.stop="removeNode(node.id)"
                                        class="ml-auto text-gray-400 hover:text-red-500">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            {{-- Label --}}
                            <div class="px-3 py-2">
                                <div class="text-sm font-medium text-gray-700" x-text="node.label"></div>
                                <div x-show="node.type === 'agent' && node.agent_id" class="mt-0.5 text-xs text-gray-400" x-text="getAgentName(node.agent_id)"></div>
                            </div>

                            {{-- Input port (top center) --}}
                            <div x-show="node.type !== 'start'"
                                 class="absolute -top-2 left-1/2 -translate-x-1/2 h-4 w-4 rounded-full border-2 border-gray-300 bg-white cursor-crosshair hover:border-blue-500 hover:bg-blue-50"
                                 @mouseup.stop="completeConnection(node.id)">
                            </div>

                            {{-- Output port (bottom center) --}}
                            <div x-show="node.type !== 'end'"
                                 class="absolute -bottom-2 left-1/2 -translate-x-1/2 h-4 w-4 rounded-full border-2 border-gray-300 bg-white cursor-crosshair hover:border-blue-500 hover:bg-blue-50"
                                 @mousedown.stop="startConnection(node.id, $event)">
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Zoom controls --}}
            <div class="absolute bottom-4 right-4 flex items-center gap-1 rounded-lg bg-white border border-gray-200 shadow-sm px-1">
                <button @click="zoom = Math.max(0.25, zoom - 0.1)" class="p-1.5 text-gray-500 hover:text-gray-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                </button>
                <span class="text-xs text-gray-500 w-10 text-center" x-text="Math.round(zoom * 100) + '%'"></span>
                <button @click="zoom = Math.min(2, zoom + 0.1)" class="p-1.5 text-gray-500 hover:text-gray-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                </button>
                <button @click="zoom = 1; panX = 0; panY = 0" class="p-1.5 text-gray-500 hover:text-gray-700 border-l border-gray-200 ml-1">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                </button>
            </div>
        </div>

        {{-- Right Panel: Node config --}}
        <div x-show="selectedNodeId" x-cloak class="w-72 flex-shrink-0 overflow-y-auto border-l border-gray-200 bg-white p-4">
            <template x-if="selectedNode">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Node Configuration</h3>

                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Label</label>
                            <input type="text" x-model="selectedNode.label" @input="syncToLivewire()"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>

                        <template x-if="selectedNode.type === 'agent'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Agent</label>
                                    <select x-model="selectedNode.agent_id" @change="syncToLivewire()"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="">Select agent...</option>
                                        <template x-for="agent in agents" :key="agent.id">
                                            <option :value="agent.id" x-text="agent.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Skill (optional)</label>
                                    <select x-model="selectedNode.skill_id" @change="syncToLivewire()"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="">Default skill</option>
                                        <template x-for="skill in skills" :key="skill.id">
                                            <option :value="skill.id" x-text="skill.name"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'conditional'">
                            <div class="rounded-lg bg-yellow-50 p-3 text-xs text-yellow-700">
                                Configure conditions on the outgoing edges. Click an edge to set its condition.
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Edge config (when edge selected) --}}
            <template x-if="selectedEdgeId && !selectedNodeId">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Edge Configuration</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Label</label>
                            <input type="text" :value="getSelectedEdge()?.label" @input="updateEdgeLabel($event.target.value)"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="checkbox" :checked="getSelectedEdge()?.is_default" @change="toggleEdgeDefault()"
                                   class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                            <label class="text-xs text-gray-600">Default (fallback) edge</label>
                        </div>
                        <button @click="removeEdge(selectedEdgeId)" class="w-full rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">
                            Delete Edge
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

@script
<script>
Alpine.data('workflowBuilder', (initialNodes, initialEdges, agents, skills) => ({
    localNodes: initialNodes,
    localEdges: initialEdges,
    agents: agents,
    skills: skills,

    // Selection state
    selectedNodeId: null,
    selectedEdgeId: null,

    // Dragging state
    isDragging: false,
    dragNodeId: null,
    dragOffsetX: 0,
    dragOffsetY: 0,

    // Connection state
    isConnecting: false,
    connectFromNodeId: null,
    connectFromX: 0,
    connectFromY: 0,
    connectToX: 0,
    connectToY: 0,

    // Pan/zoom
    panX: 0,
    panY: 0,
    zoom: 1,
    isPanning: false,
    panStartX: 0,
    panStartY: 0,

    nodeCounter: initialNodes.length,

    get selectedNode() {
        if (!this.selectedNodeId) return null;
        return this.localNodes.find(n => n.id === this.selectedNodeId) || null;
    },

    addNode(type) {
        this.nodeCounter++;
        const id = 'node-' + Date.now() + '-' + this.nodeCounter;
        const labels = { agent: 'Agent ' + this.nodeCounter, conditional: 'Condition', end: 'End' };

        this.localNodes.push({
            id: id,
            type: type,
            label: labels[type] || type,
            agent_id: null,
            skill_id: null,
            config: {},
            position_x: 250 + (this.nodeCounter * 20) % 200,
            position_y: 150 + Math.floor(this.nodeCounter / 3) * 100,
            order: this.nodeCounter,
        });

        this.syncToLivewire();
    },

    removeNode(nodeId) {
        this.localNodes = this.localNodes.filter(n => n.id !== nodeId);
        this.localEdges = this.localEdges.filter(e => e.source_node_id !== nodeId && e.target_node_id !== nodeId);
        if (this.selectedNodeId === nodeId) this.selectedNodeId = null;
        this.syncToLivewire();
    },

    selectNode(nodeId) {
        this.selectedNodeId = nodeId;
        this.selectedEdgeId = null;
    },

    selectEdge(edgeId) {
        this.selectedEdgeId = edgeId;
        this.selectedNodeId = null;
    },

    // Drag nodes
    startDragNode(nodeId, event) {
        const node = this.localNodes.find(n => n.id === nodeId);
        if (!node) return;
        this.isDragging = true;
        this.dragNodeId = nodeId;
        this.dragOffsetX = (event.clientX / this.zoom) - node.position_x;
        this.dragOffsetY = (event.clientY / this.zoom) - node.position_y;
    },

    // Connection
    startConnection(nodeId, event) {
        const node = this.localNodes.find(n => n.id === nodeId);
        if (!node) return;
        this.isConnecting = true;
        this.connectFromNodeId = nodeId;
        this.connectFromX = node.position_x + 80;
        this.connectFromY = node.position_y + 60;
        this.connectToX = this.connectFromX;
        this.connectToY = this.connectFromY;
    },

    completeConnection(targetNodeId) {
        if (!this.isConnecting || !this.connectFromNodeId) return;
        if (this.connectFromNodeId === targetNodeId) {
            this.isConnecting = false;
            return;
        }

        // Don't duplicate
        const exists = this.localEdges.some(e =>
            e.source_node_id === this.connectFromNodeId && e.target_node_id === targetNodeId
        );

        if (!exists) {
            this.localEdges.push({
                id: 'edge-' + Date.now(),
                source_node_id: this.connectFromNodeId,
                target_node_id: targetNodeId,
                condition: null,
                label: '',
                is_default: false,
                sort_order: this.localEdges.length,
            });
            this.syncToLivewire();
        }

        this.isConnecting = false;
        this.connectFromNodeId = null;
    },

    // Pan canvas
    startPan(event) {
        this.isPanning = true;
        this.panStartX = event.clientX - this.panX;
        this.panStartY = event.clientY - this.panY;
        this.selectedNodeId = null;
        this.selectedEdgeId = null;
    },

    onMouseMove(event) {
        if (this.isDragging && this.dragNodeId) {
            const node = this.localNodes.find(n => n.id === this.dragNodeId);
            if (node) {
                node.position_x = Math.round(((event.clientX - this.panX) / this.zoom) - this.dragOffsetX + (this.panX / this.zoom));
                node.position_y = Math.round(((event.clientY - this.panY) / this.zoom) - this.dragOffsetY + (this.panY / this.zoom));
            }
        } else if (this.isConnecting) {
            this.connectToX = (event.clientX - this.panX) / this.zoom;
            this.connectToY = (event.clientY - this.panY) / this.zoom;
        } else if (this.isPanning) {
            this.panX = event.clientX - this.panStartX;
            this.panY = event.clientY - this.panStartY;
        }
    },

    onMouseUp(event) {
        if (this.isDragging) {
            this.isDragging = false;
            this.dragNodeId = null;
            this.syncToLivewire();
        }
        if (this.isConnecting) {
            this.isConnecting = false;
            this.connectFromNodeId = null;
        }
        this.isPanning = false;
    },

    // Edge rendering
    getEdgePath(edge) {
        const source = this.localNodes.find(n => n.id === edge.source_node_id);
        const target = this.localNodes.find(n => n.id === edge.target_node_id);
        if (!source || !target) return '';

        const sx = source.position_x + 80;
        const sy = source.position_y + 60;
        const tx = target.position_x + 80;
        const ty = target.position_y;

        const midY = (sy + ty) / 2;
        return `M ${sx} ${sy} C ${sx} ${midY}, ${tx} ${midY}, ${tx} ${ty}`;
    },

    getEdgeMidpoint(edge) {
        const source = this.localNodes.find(n => n.id === edge.source_node_id);
        const target = this.localNodes.find(n => n.id === edge.target_node_id);
        if (!source || !target) return { x: 0, y: 0 };
        return {
            x: (source.position_x + target.position_x) / 2 + 80,
            y: (source.position_y + 60 + target.position_y) / 2,
        };
    },

    // Edge config
    getSelectedEdge() {
        return this.localEdges.find(e => e.id === this.selectedEdgeId) || null;
    },

    updateEdgeLabel(label) {
        const edge = this.getSelectedEdge();
        if (edge) { edge.label = label; this.syncToLivewire(); }
    },

    toggleEdgeDefault() {
        const edge = this.getSelectedEdge();
        if (edge) { edge.is_default = !edge.is_default; this.syncToLivewire(); }
    },

    removeEdge(edgeId) {
        this.localEdges = this.localEdges.filter(e => e.id !== edgeId);
        this.selectedEdgeId = null;
        this.syncToLivewire();
    },

    getAgentName(agentId) {
        const agent = this.agents.find(a => a.id === agentId);
        return agent ? agent.name : '';
    },

    syncToLivewire() {
        $wire.saveGraph(this.localNodes, this.localEdges);
    },

    init() {
        // Listen for keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Delete' || e.key === 'Backspace') {
                if (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA' || document.activeElement.tagName === 'SELECT') return;
                if (this.selectedNodeId) {
                    const node = this.localNodes.find(n => n.id === this.selectedNodeId);
                    if (node && node.type !== 'start') this.removeNode(this.selectedNodeId);
                } else if (this.selectedEdgeId) {
                    this.removeEdge(this.selectedEdgeId);
                }
            }
            if (e.key === 'Escape') {
                this.selectedNodeId = null;
                this.selectedEdgeId = null;
                this.isConnecting = false;
            }
        });
    },
}));
</script>
@endscript
