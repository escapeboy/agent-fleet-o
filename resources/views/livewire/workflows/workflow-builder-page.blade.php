<div wire:ignore.self x-data="workflowBuilder(@js($nodes), @js($edges), @js($availableAgents), @js($availableSkills), @js($availableCrews))" class="flex flex-col h-[calc(100vh-8rem)]">
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
                <button @click="addNode('crew')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-teal-300 hover:bg-teal-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-teal-100 text-teal-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </span>
                    Crew Node
                </button>
                <button @click="addNode('conditional')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-yellow-300 hover:bg-yellow-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-yellow-100 text-yellow-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    </span>
                    Condition
                </button>
                <button @click="addNode('human_task')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-indigo-300 hover:bg-indigo-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-indigo-100 text-indigo-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/></svg>
                    </span>
                    Human Task
                </button>
                <button @click="addNode('switch')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-orange-300 hover:bg-orange-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-orange-100 text-orange-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                    </span>
                    Switch
                </button>
                <button @click="addNode('do_while')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-cyan-300 hover:bg-cyan-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-cyan-100 text-cyan-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </span>
                    Do While
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
                    <li>Drag empty space to pan</li>
                    <li>Scroll to zoom in/out</li>
                    <li>Drag nodes to position</li>
                    <li>Click any port → click another port to connect</li>
                    <li>Click a node to configure it</li>
                    <li>Press Delete to remove selected</li>
                </ul>
            </div>
        </div>

        {{-- Canvas (Alpine-managed: wire:ignore prevents Livewire morph from resetting x-for nodes) --}}
        <div wire:ignore class="relative flex-1 overflow-hidden bg-gray-100"
             :class="{ 'cursor-grabbing': isPanning, 'cursor-crosshair': isConnecting, 'cursor-grab': !isPanning && !isDragging && !isConnecting }"
             @mousedown="startPan($event)"
             @mousemove="onMouseMove($event)"
             @mouseup="onMouseUp($event)"
             @wheel.prevent="onWheel($event)">

            <svg class="absolute inset-0 h-full w-full" :style="'transform: translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + '); transform-origin: 0 0;'">
                {{-- Grid pattern --}}
                <defs>
                    <pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse">
                        <path d="M 20 0 L 0 0 0 20" fill="none" stroke="#e5e7eb" stroke-width="0.5"/>
                    </pattern>
                </defs>
                <rect width="5000" height="5000" x="-2500" y="-2500" fill="url(#grid)" />

                {{-- Edges (rendered programmatically — <template x-for> is invalid inside SVG) --}}
                <g x-html="renderEdgesSvg()"></g>

                {{-- Connection line (while dragging) --}}
                <line x-show="isConnecting" :x1="connectFromX" :y1="connectFromY" :x2="connectToX" :y2="connectToY"
                      stroke="#3b82f6" stroke-width="2" stroke-dasharray="4,4" />
            </svg>

            {{-- Nodes --}}
            <div class="absolute inset-0 pointer-events-none" :style="'transform: translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + '); transform-origin: 0 0;'">
                <template x-for="node in localNodes" :key="node.id">
                    <div class="absolute cursor-move select-none pointer-events-auto"
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
                                'border-teal-400': node.type === 'crew' && selectedNodeId !== node.id,
                                'border-yellow-400': node.type === 'conditional' && selectedNodeId !== node.id,
                                'border-indigo-400': node.type === 'human_task' && selectedNodeId !== node.id,
                                'border-orange-400': node.type === 'switch' && selectedNodeId !== node.id,
                                'border-cyan-400': node.type === 'do_while' && selectedNodeId !== node.id,
                             }"
                             style="min-width: 160px;">

                            {{-- Header --}}
                            <div class="flex items-center gap-2 px-3 py-2 rounded-t-lg"
                                 :class="{
                                    'bg-green-50': node.type === 'start',
                                    'bg-red-50': node.type === 'end',
                                    'bg-purple-50': node.type === 'agent',
                                    'bg-teal-50': node.type === 'crew',
                                    'bg-yellow-50': node.type === 'conditional',
                                    'bg-indigo-50': node.type === 'human_task',
                                    'bg-orange-50': node.type === 'switch',
                                    'bg-cyan-50': node.type === 'do_while',
                                 }">
                                <span class="text-xs font-medium uppercase"
                                      :class="{
                                         'text-green-700': node.type === 'start',
                                         'text-red-700': node.type === 'end',
                                         'text-purple-700': node.type === 'agent',
                                         'text-teal-700': node.type === 'crew',
                                         'text-yellow-700': node.type === 'conditional',
                                         'text-indigo-700': node.type === 'human_task',
                                         'text-orange-700': node.type === 'switch',
                                         'text-cyan-700': node.type === 'do_while',
                                      }" x-text="nodeTypeLabel(node.type)"></span>
                                <button x-show="node.type !== 'start'" @click.stop="removeNode(node.id)"
                                        class="ml-auto text-gray-400 hover:text-red-500">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            {{-- Label --}}
                            <div class="px-3 py-2">
                                <div class="text-sm font-medium text-gray-700" x-text="node.label"></div>
                                <div x-show="node.type === 'agent' && node.agent_id" class="mt-0.5 text-xs text-gray-400" x-text="getAgentName(node.agent_id)"></div>
                                <div x-show="node.type === 'crew' && node.crew_id" class="mt-0.5 text-xs text-teal-500" x-text="getCrewName(node.crew_id)"></div>
                            </div>

                            {{-- Input port (top center) --}}
                            <div x-show="node.type !== 'start'"
                                 class="absolute -top-3 left-1/2 -translate-x-1/2 h-6 w-6 rounded-full border-2 border-gray-300 bg-white cursor-crosshair hover:border-blue-500 hover:bg-blue-50 hover:scale-125 transition-transform z-10"
                                 :class="{ 'border-blue-500 bg-blue-50 scale-125': isConnecting }"
                                 @mousedown.stop="handlePortClick(node.id, $event)"
                                 @click.stop>
                            </div>

                            {{-- Output port (bottom center) --}}
                            <div x-show="node.type !== 'end'"
                                 class="absolute -bottom-3 left-1/2 -translate-x-1/2 h-6 w-6 rounded-full border-2 border-gray-300 bg-white cursor-crosshair hover:border-blue-500 hover:bg-blue-50 hover:scale-125 transition-transform z-10"
                                 :class="{ 'border-blue-500 bg-blue-50 scale-125': isConnecting }"
                                 @mousedown.stop="handlePortClick(node.id, $event)"
                                 @click.stop>
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

        {{-- Right Panel: Node config (Alpine-managed: wire:ignore prevents morph from collapsing x-if) --}}
        <div wire:ignore x-show="selectedNodeId || selectedEdgeId" x-cloak class="w-72 flex-shrink-0 overflow-y-auto border-l border-gray-200 bg-white p-4">
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

                        <template x-if="selectedNode.type === 'crew'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Crew</label>
                                    <select x-model="selectedNode.crew_id" @change="syncToLivewire()"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="">Select crew...</option>
                                        <template x-for="crew in crews" :key="crew.id">
                                            <option :value="crew.id" x-text="crew.name + ' (' + crew.process_type + ')'"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="rounded-lg bg-teal-50 p-3 text-xs text-teal-700">
                                    The crew's coordinator will decompose incoming context into tasks and delegate to workers.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'conditional'">
                            <div class="rounded-lg bg-yellow-50 p-3 text-xs text-yellow-700">
                                Configure conditions on the outgoing edges. Click an edge to set its condition.
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'human_task'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Instructions</label>
                                    <textarea x-model="selectedNode.config.prompt" @input="syncToLivewire()" rows="3"
                                              class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500"
                                              placeholder="Instructions for the human reviewer..."></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">SLA Hours</label>
                                    <input type="number" x-model="selectedNode.config.sla_hours" @input="syncToLivewire()" min="1"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500"
                                           placeholder="48" />
                                </div>
                                <div class="rounded-lg bg-indigo-50 p-3 text-xs text-indigo-700">
                                    Human task pauses the workflow until a team member submits a form response. Configure the form_schema in JSON config.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'switch'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Expression</label>
                                    <input type="text" x-model="selectedNode.config.expression" @input="syncToLivewire()"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500"
                                           placeholder="output.category" />
                                </div>
                                <div class="rounded-lg bg-orange-50 p-3 text-xs text-orange-700">
                                    The expression value is matched against case_value on each outgoing edge. Set case_value on edges and mark one as default.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'do_while'">
                            <div class="space-y-3">
                                <div class="rounded-lg bg-cyan-50 p-3 text-xs text-cyan-700">
                                    Loop body runs until the break condition evaluates to true. Connect the loop body edge (non-default) and the exit edge (default). Configure break condition on outgoing edges.
                                </div>
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
                        {{-- Label --}}
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Label</label>
                            <input type="text" :value="getSelectedEdge()?.label" @input="updateEdgeLabel($event.target.value)"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                        </div>

                        {{-- Condition Editor (hidden when default) --}}
                        <div x-show="!getSelectedEdge()?.is_default" x-transition>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Condition</label>

                            {{-- Mode selector --}}
                            <div class="flex gap-1 mb-2">
                                <button @click="conditionMode = 'simple'; if (conditionRules.length > 1) conditionRules = [conditionRules[0]]; syncConditionToEdge()"
                                        class="rounded px-2 py-1 text-xs font-medium"
                                        :class="conditionMode === 'simple' ? 'bg-primary-100 text-primary-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                                    Single
                                </button>
                                <button @click="conditionMode = 'all'; syncConditionToEdge()"
                                        class="rounded px-2 py-1 text-xs font-medium"
                                        :class="conditionMode === 'all' ? 'bg-primary-100 text-primary-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                                    All (AND)
                                </button>
                                <button @click="conditionMode = 'any'; syncConditionToEdge()"
                                        class="rounded px-2 py-1 text-xs font-medium"
                                        :class="conditionMode === 'any' ? 'bg-primary-100 text-primary-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                                    Any (OR)
                                </button>
                            </div>

                            {{-- Source node hint --}}
                            <div x-show="getSourceNodeLabel()" class="mb-2 text-xs text-gray-400">
                                From: <span class="font-medium text-gray-600" x-text="getSourceNodeLabel()"></span>
                            </div>

                            {{-- Rules --}}
                            <div class="space-y-2">
                                <template x-for="(rule, index) in conditionRules" :key="index">
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-2 space-y-1.5">
                                        <div class="flex items-center gap-1">
                                            <input type="text" x-model="rule.field" @input="syncConditionToEdge()" placeholder="output.field"
                                                   class="flex-1 rounded border border-gray-300 px-2 py-1 text-xs focus:border-primary-500 focus:ring-primary-500" />
                                            <button x-show="conditionRules.length > 1" @click="removeConditionRule(index)"
                                                    class="text-gray-400 hover:text-red-500 p-0.5">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                        <select x-model="rule.operator" @change="syncConditionToEdge()"
                                                class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-primary-500 focus:ring-primary-500">
                                            <template x-for="op in conditionOperators" :key="op.value">
                                                <option :value="op.value" x-text="op.label"></option>
                                            </template>
                                        </select>
                                        <div x-show="!nullOperators.includes(rule.operator)">
                                            <input type="text" x-model="rule.value" @input="syncConditionToEdge()" placeholder="value"
                                                   class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-primary-500 focus:ring-primary-500" />
                                            <div x-show="rule.operator === 'in' || rule.operator === 'not_in'" class="mt-0.5 text-[10px] text-gray-400">
                                                JSON array, e.g. ["a","b"]
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            {{-- Add rule / Clear --}}
                            <div class="flex items-center gap-2 mt-2">
                                <button x-show="conditionMode !== 'simple'" @click="addConditionRule()"
                                        class="flex items-center gap-1 rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">
                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    Add Rule
                                </button>
                                <button @click="conditionRules = [{field:'', operator:'==', value:''}]; syncConditionToEdge()"
                                        class="text-xs text-gray-400 hover:text-gray-600">Clear</button>
                            </div>

                            {{-- Field reference help --}}
                            <div class="mt-2 rounded-lg bg-amber-50 p-2 text-[10px] text-amber-700">
                                <p class="font-medium mb-0.5">Field reference:</p>
                                <ul class="space-y-0.5">
                                    <li><code class="bg-amber-100 px-0.5 rounded">output.field</code> — predecessor output</li>
                                    <li><code class="bg-amber-100 px-0.5 rounded">node:&lbrace;id&rbrace;.output.field</code> — specific node</li>
                                    <li><code class="bg-amber-100 px-0.5 rounded">experiment.field</code> — experiment data</li>
                                </ul>
                            </div>
                        </div>

                        {{-- Default checkbox --}}
                        <div class="flex items-center gap-2">
                            <input type="checkbox" :checked="getSelectedEdge()?.is_default" @change="toggleEdgeDefault()"
                                   class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                            <label class="text-xs text-gray-600">Default (fallback) edge</label>
                        </div>

                        {{-- Case value (for switch edges) --}}
                        <div x-show="isSwitchEdge()">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Case Value</label>
                            <input type="text" :value="getSelectedEdge()?.case_value"
                                   @input="let e = getSelectedEdge(); if(e) { e.case_value = $event.target.value; syncToLivewire(); }"
                                   placeholder="e.g. approved, rejected..."
                                   class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                            <p class="mt-0.5 text-[10px] text-gray-400">Matched against the switch expression value</p>
                        </div>

                        {{-- Sort order --}}
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Priority (sort order)</label>
                            <input type="number" :value="getSelectedEdge()?.sort_order"
                                   @input="let e = getSelectedEdge(); if(e) { e.sort_order = parseInt($event.target.value) || 0; syncToLivewire(); }"
                                   min="0" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                            <p class="mt-0.5 text-[10px] text-gray-400">Lower = evaluated first</p>
                        </div>

                        {{-- Delete --}}
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
Alpine.data('workflowBuilder', (initialNodes, initialEdges, agents, skills, crews) => ({
    localNodes: initialNodes,
    localEdges: initialEdges,
    agents: agents,
    skills: skills,
    crews: crews || [],

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

    // Condition editor state
    conditionMode: 'simple',
    conditionRules: [],
    conditionOperators: [
        {value: '==', label: '== (equals)'},
        {value: '!=', label: '!= (not equals)'},
        {value: '>', label: '> (greater than)'},
        {value: '<', label: '< (less than)'},
        {value: '>=', label: '>= (greater or equal)'},
        {value: '<=', label: '<= (less or equal)'},
        {value: 'contains', label: 'contains'},
        {value: 'not_contains', label: 'not contains'},
        {value: 'in', label: 'in (list)'},
        {value: 'not_in', label: 'not in (list)'},
        {value: 'is_null', label: 'is null'},
        {value: 'is_not_null', label: 'is not null'},
    ],
    nullOperators: ['is_null', 'is_not_null'],

    get selectedNode() {
        if (!this.selectedNodeId) return null;
        return this.localNodes.find(n => n.id === this.selectedNodeId) || null;
    },

    addNode(type) {
        this.nodeCounter++;
        const id = 'node-' + Date.now() + '-' + this.nodeCounter;
        const labels = { agent: 'Agent ' + this.nodeCounter, crew: 'Crew ' + this.nodeCounter, conditional: 'Condition', human_task: 'Human Task ' + this.nodeCounter, switch: 'Switch', do_while: 'Do While', dynamic_fork: 'Dynamic Fork', end: 'End' };

        this.localNodes.push({
            id: id,
            type: type,
            label: labels[type] || type,
            agent_id: null,
            skill_id: null,
            crew_id: null,
            config: {},
            position_x: 250 + (this.nodeCounter * 20) % 200,
            position_y: 150 + Math.floor(this.nodeCounter / 3) * 100,
            order: this.nodeCounter,
        });

        this.syncToLivewireNow();
    },

    removeNode(nodeId) {
        this.localNodes = this.localNodes.filter(n => n.id !== nodeId);
        this.localEdges = this.localEdges.filter(e => e.source_node_id !== nodeId && e.target_node_id !== nodeId);
        if (this.selectedNodeId === nodeId) this.selectedNodeId = null;
        this.syncToLivewireNow();
    },

    selectNode(nodeId) {
        this.selectedNodeId = nodeId;
        this.selectedEdgeId = null;
    },

    selectEdge(edgeId) {
        this.selectedEdgeId = edgeId;
        this.selectedNodeId = null;
        this.loadConditionFromEdge();
    },

    // Drag nodes
    startDragNode(nodeId, event) {
        // If we're in connection mode, cancel it instead of dragging
        if (this.isConnecting) {
            this.cancelConnection();
            return;
        }
        const node = this.localNodes.find(n => n.id === nodeId);
        if (!node) return;
        this.isDragging = true;
        this.dragNodeId = nodeId;
        this.dragOffsetX = (event.clientX / this.zoom) - node.position_x;
        this.dragOffsetY = (event.clientY / this.zoom) - node.position_y;
    },

    // Connection (click any port to start, click any other port to finish)
    handlePortClick(nodeId, event) {
        if (!this.isConnecting) {
            // Start connection from this node
            const node = this.localNodes.find(n => n.id === nodeId);
            if (!node) return;
            this.isConnecting = true;
            this.connectFromNodeId = nodeId;
            this.connectFromX = node.position_x + 80;
            this.connectFromY = node.position_y + 60;
            this.connectToX = this.connectFromX;
            this.connectToY = this.connectFromY;
        } else {
            // Complete connection to this node
            if (this.connectFromNodeId === nodeId) {
                this.cancelConnection();
                return;
            }

            // Don't duplicate (check both directions)
            const exists = this.localEdges.some(e =>
                (e.source_node_id === this.connectFromNodeId && e.target_node_id === nodeId) ||
                (e.source_node_id === nodeId && e.target_node_id === this.connectFromNodeId)
            );

            if (!exists) {
                this.localEdges.push({
                    id: 'edge-' + Date.now(),
                    source_node_id: this.connectFromNodeId,
                    target_node_id: nodeId,
                    condition: null,
                    label: '',
                    is_default: false,
                    sort_order: this.localEdges.length,
                });
                this.syncToLivewireNow();
            }

            this.cancelConnection();
        }
    },

    cancelConnection() {
        this.isConnecting = false;
        this.connectFromNodeId = null;
    },

    // Pan canvas (only if not clicking on a node/port — those use @mousedown.stop)
    startPan(event) {
        // If we're in connection mode, cancel it instead of panning
        if (this.isConnecting) {
            this.cancelConnection();
            return;
        }
        this.isPanning = true;
        this.panStartX = event.clientX - this.panX;
        this.panStartY = event.clientY - this.panY;
        this.selectedNodeId = null;
        this.selectedEdgeId = null;
    },

    // Scroll-wheel zoom (centered on cursor)
    onWheel(event) {
        const delta = event.deltaY > 0 ? -0.05 : 0.05;
        const newZoom = Math.min(2, Math.max(0.25, this.zoom + delta));
        if (newZoom === this.zoom) return;

        // Zoom toward cursor position
        const rect = event.currentTarget.getBoundingClientRect();
        const cx = event.clientX - rect.left;
        const cy = event.clientY - rect.top;
        const scale = newZoom / this.zoom;
        this.panX = cx - (cx - this.panX) * scale;
        this.panY = cy - (cy - this.panY) * scale;
        this.zoom = newZoom;
    },

    onMouseMove(event) {
        if (this.isDragging && this.dragNodeId) {
            const node = this.localNodes.find(n => n.id === this.dragNodeId);
            if (node) {
                node.position_x = Math.round(((event.clientX - this.panX) / this.zoom) - this.dragOffsetX + (this.panX / this.zoom));
                node.position_y = Math.round(((event.clientY - this.panY) / this.zoom) - this.dragOffsetY + (this.panY / this.zoom));
            }
        } else if (this.isPanning) {
            this.panX = event.clientX - this.panStartX;
            this.panY = event.clientY - this.panStartY;
        }

        // Always track connection line when in connecting mode (even without button held)
        if (this.isConnecting) {
            const rect = event.currentTarget.getBoundingClientRect();
            this.connectToX = (event.clientX - rect.left - this.panX) / this.zoom;
            this.connectToY = (event.clientY - rect.top - this.panY) / this.zoom;
        }
    },

    onMouseUp(event) {
        if (this.isDragging) {
            this.isDragging = false;
            this.dragNodeId = null;
            this.syncToLivewire();
        }
        // Don't cancel connection on mouseup — connection uses click-to-start, click-to-finish
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
        if (edge) {
            edge.is_default = !edge.is_default;
            if (edge.is_default) {
                edge.condition = null;
                this.conditionRules = [{field: '', operator: '==', value: ''}];
                this.conditionMode = 'simple';
            }
            this.syncToLivewire();
        }
    },

    removeEdge(edgeId) {
        this.localEdges = this.localEdges.filter(e => e.id !== edgeId);
        this.selectedEdgeId = null;
        this.syncToLivewireNow();
    },

    // Condition editor methods
    loadConditionFromEdge() {
        const edge = this.getSelectedEdge();
        if (!edge || !edge.condition) {
            this.conditionMode = 'simple';
            this.conditionRules = [{field: '', operator: '==', value: ''}];
            return;
        }
        const c = edge.condition;
        if (c.all && Array.isArray(c.all)) {
            this.conditionMode = 'all';
            this.conditionRules = c.all.map(r => ({
                field: r.field || '',
                operator: r.operator || '==',
                value: this.serializeValue(r.value),
            }));
        } else if (c.any && Array.isArray(c.any)) {
            this.conditionMode = 'any';
            this.conditionRules = c.any.map(r => ({
                field: r.field || '',
                operator: r.operator || '==',
                value: this.serializeValue(r.value),
            }));
        } else if (c.field) {
            this.conditionMode = 'simple';
            this.conditionRules = [{
                field: c.field || '',
                operator: c.operator || '==',
                value: this.serializeValue(c.value),
            }];
        } else {
            this.conditionMode = 'simple';
            this.conditionRules = [{field: '', operator: '==', value: ''}];
        }
    },

    syncConditionToEdge() {
        const edge = this.getSelectedEdge();
        if (!edge) return;

        const validRules = this.conditionRules.filter(r => r.field.trim() !== '');
        if (validRules.length === 0) {
            edge.condition = null;
        } else if (this.conditionMode === 'simple' || validRules.length === 1) {
            const r = validRules[0];
            edge.condition = {field: r.field, operator: r.operator, value: this.parseValue(r.value)};
        } else if (this.conditionMode === 'all') {
            edge.condition = {all: validRules.map(r => ({field: r.field, operator: r.operator, value: this.parseValue(r.value)}))};
        } else {
            edge.condition = {any: validRules.map(r => ({field: r.field, operator: r.operator, value: this.parseValue(r.value)}))};
        }
        this.syncToLivewire();
    },

    parseValue(raw) {
        if (raw === undefined || raw === null || raw === '') return null;
        const trimmed = String(raw).trim();
        if (trimmed === 'true') return true;
        if (trimmed === 'false') return false;
        if (trimmed === 'null') return null;
        if (!isNaN(trimmed) && trimmed !== '') return Number(trimmed);
        try {
            const parsed = JSON.parse(trimmed);
            if (Array.isArray(parsed)) return parsed;
        } catch (e) {}
        return trimmed;
    },

    serializeValue(val) {
        if (val === null || val === undefined) return '';
        if (typeof val === 'boolean') return String(val);
        if (Array.isArray(val)) return JSON.stringify(val);
        return String(val);
    },

    addConditionRule() {
        this.conditionRules.push({field: '', operator: '==', value: ''});
    },

    removeConditionRule(index) {
        if (this.conditionRules.length <= 1) return;
        this.conditionRules.splice(index, 1);
        this.syncConditionToEdge();
    },

    isSwitchEdge() {
        const edge = this.getSelectedEdge();
        if (!edge) return false;
        const source = this.localNodes.find(n => n.id === edge.source_node_id);
        return source && source.type === 'switch';
    },

    getSourceNodeLabel() {
        const edge = this.getSelectedEdge();
        if (!edge) return '';
        const source = this.localNodes.find(n => n.id === edge.source_node_id);
        return source ? source.label : '';
    },

    // Render edges as raw SVG (avoids <template x-for> inside <svg>)
    renderEdgesSvg() {
        return this.localEdges.map(edge => {
            const path = this.getEdgePath(edge);
            const mid = this.getEdgeMidpoint(edge);
            const stroke = this.selectedEdgeId === edge.id ? '#3b82f6' : (edge.is_default ? '#9ca3af' : '#6b7280');
            const dash = edge.is_default ? '5,5' : 'none';
            const labelHtml = edge.label
                ? `<text x="${mid.x}" y="${mid.y - 8}" text-anchor="middle" class="text-xs fill-gray-500 pointer-events-none">${this.escapeHtml(edge.label)}</text>`
                : '';
            const conditionHtml = edge.condition
                ? `<text x="${mid.x + (edge.label ? 30 : 0)}" y="${mid.y - 6}" text-anchor="middle" font-size="12" class="pointer-events-none" fill="#d97706">\u26A1</text>`
                : '';
            return `<g data-edge-id="${edge.id}" style="cursor:pointer">
                <path d="${path}" fill="none" stroke="${stroke}" stroke-width="2" stroke-dasharray="${dash}" />
                <path d="${path}" fill="none" stroke="transparent" stroke-width="12" />
                ${labelHtml}
                ${conditionHtml}
            </g>`;
        }).join('');
    },

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    getAgentName(agentId) {
        const agent = this.agents.find(a => a.id === agentId);
        return agent ? agent.name : '';
    },

    getCrewName(crewId) {
        const crew = this.crews.find(c => c.id === crewId);
        return crew ? crew.name : '';
    },

    nodeTypeLabel(type) {
        const labels = { start: 'Start', end: 'End', agent: 'Agent', crew: 'Crew', conditional: 'Condition', human_task: 'Human Task', switch: 'Switch', dynamic_fork: 'Dynamic Fork', do_while: 'Do While' };
        return labels[type] || type;
    },

    syncToLivewire() {
        clearTimeout(this._syncTimeout);
        this._syncTimeout = setTimeout(() => {
            $wire.saveGraph(this.localNodes, this.localEdges);
        }, 500);
    },

    syncToLivewireNow() {
        clearTimeout(this._syncTimeout);
        $wire.saveGraph(this.localNodes, this.localEdges);
    },

    init() {
        // Delegate click events for programmatically rendered edges
        this.$el.addEventListener('click', (e) => {
            const edgeGroup = e.target.closest('[data-edge-id]');
            if (edgeGroup) {
                e.stopPropagation();
                this.selectEdge(edgeGroup.dataset.edgeId);
            }
        });

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
