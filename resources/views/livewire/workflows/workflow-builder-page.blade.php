<div wire:ignore.self wire:poll.{{ $hasRunningSteps ? '3' : '15' }}s x-data="workflowBuilder(@js($nodes), @js($edges), @js($availableAgents), @js($availableSkills), @js($availableCrews), @js($availableWorkflows))" class="flex flex-col h-[calc(100vh-8rem)]">
    {{-- Top Bar --}}
    <div class="flex items-center gap-4 border-b border-gray-200 bg-white px-4 py-3">
        <div class="flex-1 flex items-center gap-3">
            <x-form-input wire:model="name" type="text" placeholder="Workflow name..." compact />
            <x-form-input wire:model="maxLoopIterations" type="number" placeholder="Max loops" compact class="w-24" />
            <x-form-select wire:model="checkpointMode" compact class="w-32">
                <option value="sync">Sync</option>
                <option value="async">Async</option>
                <option value="exit">Exit</option>
            </x-form-select>
            <x-form-input wire:model="budgetCapCredits" type="number" placeholder="Budget cap (credits)" compact class="w-40" min="1" />
        </div>

        <div class="flex items-center gap-2">
            <button @click="$wire.save()" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Save Draft
            </button>
            <button @click="$wire.validateGraph()" class="rounded-lg border border-blue-300 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-100">
                Validate
            </button>
            <button @click="$wire.activate()" class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
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

    {{-- Execution Mode Banner --}}
    @if($executionMode)
    <div class="mx-4 mt-2 flex items-center gap-2 rounded-lg bg-yellow-50 border border-yellow-200 px-4 py-2 text-sm text-yellow-800">
        <svg class="h-4 w-4 animate-spin flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Workflow is executing — canvas editing disabled
        <a href="{{ route('experiments.show', $activeExperimentId) }}" class="ml-auto text-yellow-700 underline hover:text-yellow-900">View experiment →</a>
    </div>
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

    {{-- Validation Warnings --}}
    @if(count($validationWarnings) > 0)
        <div class="mx-4 mt-2 rounded-lg bg-yellow-50 border border-yellow-200 p-3">
            <h4 class="text-sm font-medium text-yellow-800">Workflow Warnings</h4>
            <ul class="mt-1 list-disc pl-5 text-sm text-yellow-700">
                @foreach($validationWarnings as $warning)
                    <li>{{ $warning['message'] }}</li>
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
                <button @click="addNode('time_gate')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-amber-300 hover:bg-amber-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-amber-100 text-amber-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    Time Gate
                </button>
                <button @click="addNode('merge')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-rose-300 hover:bg-rose-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-rose-100 text-rose-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"/></svg>
                    </span>
                    Merge
                </button>
                <button @click="addNode('sub_workflow')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-violet-300 hover:bg-violet-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-violet-100 text-violet-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </span>
                    Sub-Workflow
                </button>
                <button @click="addNode('llm')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-purple-300 hover:bg-purple-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-purple-100 text-purple-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    </span>
                    LLM Call
                </button>
                <button @click="addNode('http_request')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-teal-300 hover:bg-teal-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-teal-100 text-teal-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
                    </span>
                    HTTP Request
                </button>
                <button @click="addNode('parameter_extractor')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-orange-300 hover:bg-orange-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-orange-100 text-orange-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </span>
                    Param Extractor
                </button>
                <button @click="addNode('variable_aggregator')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-cyan-300 hover:bg-cyan-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-cyan-100 text-cyan-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                    </span>
                    Var Aggregator
                </button>
                <button @click="addNode('template_transform')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-lime-300 hover:bg-lime-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-lime-100 text-lime-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
                    </span>
                    Template Transform
                </button>
                <button @click="addNode('knowledge_retrieval')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-indigo-300 hover:bg-indigo-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-indigo-100 text-indigo-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </span>
                    Knowledge Retrieval
                </button>
                <button @click="addNode('boruna_step')" class="flex w-full items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:border-fuchsia-300 hover:bg-fuchsia-50">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-fuchsia-100 text-fuchsia-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </span>
                    Boruna Step
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

            <svg class="absolute inset-0 h-full w-full" x-ref="canvasSvg">
                {{-- Grid pattern (fixed in screen space, tiles across canvas) --}}
                <defs>
                    <pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse">
                        <path d="M 20 0 L 0 0 0 20" fill="none" stroke="#e5e7eb" stroke-width="0.5"/>
                    </pattern>
                </defs>
                <rect width="5000" height="5000" x="-2500" y="-2500" fill="url(#grid)" />

                {{-- Transform group mirrors the HTML node layer exactly — ensures edges always align with nodes --}}
                <g :transform="`translate(${panX} ${panY}) scale(${zoom})`">
                    {{-- Edges (rendered programmatically — <template x-for> is invalid inside SVG) --}}
                    <g x-html="renderEdgesSvg()"></g>

                    {{-- Connection line (while dragging) --}}
                    <line x-show="isConnecting" :x1="connectFromX" :y1="connectFromY" :x2="connectToX" :y2="connectToY"
                          stroke="#3b82f6" stroke-width="2" stroke-dasharray="4,4" />
                </g>
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
                                'border-amber-400': node.type === 'time_gate' && selectedNodeId !== node.id,
                                'border-rose-400': node.type === 'merge' && selectedNodeId !== node.id,
                                'border-violet-400': node.type === 'sub_workflow' && selectedNodeId !== node.id,
                                'ring-2 ring-yellow-400 animate-pulse': stepStatuses[node.id]?.status === 'running',
                                'ring-2 ring-green-500': stepStatuses[node.id]?.status === 'completed',
                                'ring-2 ring-red-500': stepStatuses[node.id]?.status === 'failed',
                                'ring-2 ring-blue-400': stepStatuses[node.id]?.status === 'waiting_human',
                                'opacity-50': stepStatuses[node.id]?.status === 'skipped',
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
                                    'bg-amber-50': node.type === 'time_gate',
                                    'bg-rose-50': node.type === 'merge',
                                    'bg-violet-50': node.type === 'sub_workflow',
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
                                         'text-amber-700': node.type === 'time_gate',
                                         'text-rose-700': node.type === 'merge',
                                         'text-violet-700': node.type === 'sub_workflow',
                                      }" x-text="nodeTypeLabel(node.type)"></span>
                                <button x-show="node.type !== 'start'" @click.stop="removeNode(node.id)"
                                        class="ml-auto text-gray-400 hover:text-red-500">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            {{-- Execution status dot --}}
                            <template x-if="stepStatuses[node.id]">
                                <div class="absolute -top-1.5 -right-1.5 z-10">
                                    <span class="relative flex h-3 w-3">
                                        <span x-show="stepStatuses[node.id]?.status === 'running'" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-3 w-3"
                                              :class="{
                                                  'bg-yellow-500': stepStatuses[node.id]?.status === 'running',
                                                  'bg-green-500': stepStatuses[node.id]?.status === 'completed',
                                                  'bg-red-500': stepStatuses[node.id]?.status === 'failed',
                                                  'bg-blue-500': stepStatuses[node.id]?.status === 'waiting_human',
                                                  'bg-gray-400': stepStatuses[node.id]?.status === 'skipped',
                                              }"></span>
                                    </span>
                                </div>
                            </template>

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

        {{-- Execution Sidebar Panel (slides in when workflow is executing) --}}
        @if($activeExperimentId)
        <div class="w-64 flex-shrink-0 border-l border-gray-200 bg-white overflow-y-auto flex flex-col">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Live Execution</span>
                @if($executionMode)
                <span class="ml-auto flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-2 w-2 rounded-full bg-yellow-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-yellow-500"></span>
                </span>
                @endif
            </div>

            @if(count($stepStatuses) > 0)
            <div class="divide-y divide-gray-50 flex-1">
                @foreach($stepStatuses as $nodeId => $step)
                @php
                    $node = collect($nodes)->firstWhere('id', $nodeId);
                    $statusColor = match($step['status']) {
                        'running' => 'text-yellow-600',
                        'completed' => 'text-green-600',
                        'failed' => 'text-red-600',
                        'waiting_human' => 'text-blue-600',
                        'skipped' => 'text-gray-400',
                        default => 'text-gray-500',
                    };
                    $statusIcon = match($step['status']) {
                        'running' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
                        'completed' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                        'failed' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
                        'waiting_human' => 'M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0',
                        default => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                    };
                @endphp
                <div class="px-4 py-2.5 flex items-center gap-2 min-w-0">
                    <svg class="h-4 w-4 flex-shrink-0 {{ $statusColor }}{{ $step['status'] === 'running' ? ' animate-spin' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $statusIcon }}"/>
                    </svg>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs text-gray-700 truncate">{{ $node['label'] ?? $nodeId }}</p>
                        @if($step['error'])
                            <p class="text-xs text-red-500 truncate" title="{{ $step['error'] }}">{{ Str::limit($step['error'], 40) }}</p>
                        @endif
                    </div>
                    @if($step['duration'])
                    <span class="text-xs text-gray-400 flex-shrink-0">{{ round($step['duration'] / 1000, 1) }}s</span>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            <div class="px-4 py-6 text-center">
                <p class="text-xs text-gray-400">No steps tracked yet</p>
            </div>
            @endif

            <div class="px-4 py-3 border-t border-gray-100 mt-auto">
                <a href="{{ route('experiments.show', $activeExperimentId) }}"
                   class="flex items-center gap-1 text-xs text-primary-600 hover:text-primary-700 hover:underline">
                    View full experiment
                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </a>
            </div>
        </div>
        @endif

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

                                {{-- Structured Output Schema --}}
                                <div x-data="{
                                    schemaError: '',
                                    get schemaText() {
                                        const s = selectedNode.config?.output_schema;
                                        if (!s) return '';
                                        try { return JSON.stringify(s, null, 2); } catch { return ''; }
                                    },
                                    onSchemaInput(val) {
                                        if (!val.trim()) {
                                            this.schemaError = '';
                                            if (selectedNode.config) delete selectedNode.config.output_schema;
                                            syncToLivewire();
                                            return;
                                        }
                                        try {
                                            const parsed = JSON.parse(val);
                                            this.schemaError = '';
                                            if (!selectedNode.config) selectedNode.config = {};
                                            selectedNode.config.output_schema = parsed;
                                            syncToLivewire();
                                        } catch (e) {
                                            this.schemaError = e.message;
                                        }
                                    }
                                }">
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="text-xs font-medium text-gray-600">Output Schema (JSON)</label>
                                        <button type="button"
                                                @click="
                                                    if (!selectedNode.config) selectedNode.config = {};
                                                    selectedNode.config.output_schema = {type:'object',properties:{result:{type:'string'},confidence:{type:'number'}},required:['result']};
                                                    schemaError = '';
                                                    syncToLivewire();
                                                    $nextTick(() => $el.closest('.space-y-3').querySelector('textarea').value = JSON.stringify(selectedNode.config.output_schema, null, 2));
                                                "
                                                class="text-[10px] text-primary-600 hover:text-primary-700">
                                            Insert template
                                        </button>
                                    </div>
                                    <textarea
                                        :value="schemaText"
                                        @input.debounce.400ms="onSchemaInput($event.target.value)"
                                        rows="5"
                                        placeholder='{"type":"object","properties":{...}}'
                                        class="w-full rounded-lg border px-3 py-1.5 text-xs font-mono focus:ring-primary-500 focus:border-primary-500"
                                        :class="schemaError ? 'border-red-400 bg-red-50' : 'border-gray-300'"></textarea>
                                    <p x-show="schemaError" x-text="schemaError" class="mt-0.5 text-[10px] text-red-600"></p>
                                    <p x-show="!schemaError && selectedNode.config?.output_schema" class="mt-0.5 text-[10px] text-green-600">Valid JSON schema</p>
                                    <p class="mt-0.5 text-[10px] text-gray-400">Leave empty for free-form output. When set, the agent's response must conform to this schema.</p>
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

                        <template x-if="selectedNode.type === 'time_gate'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Delay (seconds)</label>
                                    <input type="number" x-model="selectedNode.config.delay_seconds" @input="syncToLivewire()" min="1"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500"
                                           placeholder="3600" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Or delay until (ISO 8601, optional)</label>
                                    <input type="text" x-model="selectedNode.config.delay_until" @input="syncToLivewire()"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500"
                                           placeholder="2026-03-01T09:00:00Z" />
                                </div>
                                <div class="rounded-lg bg-amber-50 p-3 text-xs text-amber-700">
                                    Pauses workflow execution for the specified duration. <em>delay_until</em> takes priority over <em>delay_seconds</em> when both are set.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'merge'">
                            <div class="space-y-3">
                                <div class="rounded-lg bg-rose-50 p-3 text-xs text-rose-700">
                                    Merge node uses OR-join semantics — execution continues as soon as the <em>first</em> incoming branch completes. Connect multiple incoming edges and one outgoing edge.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'sub_workflow'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Sub-Workflow</label>
                                    <select x-model="selectedNode.sub_workflow_id" @change="syncToLivewire()"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="">Select workflow...</option>
                                        <template x-for="wf in workflows" :key="wf.id">
                                            <option :value="wf.id" x-text="wf.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="rounded-lg bg-violet-50 p-3 text-xs text-violet-700">
                                    Spawns a child experiment from the selected workflow. The parent workflow waits until the child reaches a terminal state before continuing.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'llm'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Prompt</label>
                                    <textarea x-model="selectedNode.prompt" @input="syncToLivewire()" rows="4"
                                              placeholder="Enter your prompt. Use {{variable}} for interpolation."
                                              class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-mono focus:border-primary-500 focus:ring-primary-500"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Model</label>
                                    <select x-model="selectedNode.model" @change="syncToLivewire()"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="">Use team default</option>
                                        <option value="anthropic/claude-sonnet-4-5">Claude Sonnet 4.5</option>
                                        <option value="anthropic/claude-haiku-4-5">Claude Haiku 4.5</option>
                                        <option value="openai/gpt-4o">GPT-4o</option>
                                        <option value="openai/gpt-4o-mini">GPT-4o Mini</option>
                                        <option value="google/gemini-2.5-flash">Gemini 2.5 Flash</option>
                                        <option value="google/gemini-2.5-pro">Gemini 2.5 Pro</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Output variable name</label>
                                    <input type="text" x-model="selectedNode.output_variable" @input="syncToLivewire()"
                                           placeholder="e.g. llm_output"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div class="rounded-lg bg-purple-50 p-3 text-xs text-purple-700">
                                    Calls the LLM directly with the given prompt. Output is stored as a workflow variable for use in downstream nodes.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'http_request'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Method</label>
                                    <select x-model="selectedNode.method" @change="syncToLivewire()"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="GET">GET</option>
                                        <option value="POST">POST</option>
                                        <option value="PUT">PUT</option>
                                        <option value="PATCH">PATCH</option>
                                        <option value="DELETE">DELETE</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">URL</label>
                                    <input type="text" x-model="selectedNode.url" @input="syncToLivewire()"
                                           placeholder="https://api.example.com/endpoint"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Headers (JSON)</label>
                                    <textarea x-model="selectedNode.headers" @input="syncToLivewire()" rows="2"
                                              placeholder='{"Authorization": "Bearer {{token}}"}'
                                              class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-mono focus:border-primary-500 focus:ring-primary-500"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Body (JSON)</label>
                                    <textarea x-model="selectedNode.body" @input="syncToLivewire()" rows="3"
                                              placeholder='{"key": "{{variable}}"}'
                                              class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-mono focus:border-primary-500 focus:ring-primary-500"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Output variable name</label>
                                    <input type="text" x-model="selectedNode.output_variable" @input="syncToLivewire()"
                                           placeholder="e.g. api_response"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div class="rounded-lg bg-teal-50 p-3 text-xs text-teal-700">
                                    Makes an HTTP request to an external API. Use {{variable}} syntax for dynamic values from prior steps.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'parameter_extractor'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Source variable</label>
                                    <input type="text" x-model="selectedNode.source_variable" @input="syncToLivewire()"
                                           placeholder="e.g. llm_output"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Extraction schema (JSON)</label>
                                    <textarea x-model="selectedNode.schema" @input="syncToLivewire()" rows="4"
                                              placeholder='{"name": "string", "score": "number"}'
                                              class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-mono focus:border-primary-500 focus:ring-primary-500"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Output variable prefix</label>
                                    <input type="text" x-model="selectedNode.output_prefix" @input="syncToLivewire()"
                                           placeholder="e.g. extracted (→ extracted.name, extracted.score)"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div class="rounded-lg bg-orange-50 p-3 text-xs text-orange-700">
                                    Extracts structured parameters from a text variable using LLM parsing. Outputs each field as a separate workflow variable.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'variable_aggregator'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Variables to aggregate (comma-separated)</label>
                                    <input type="text" x-model="selectedNode.variables" @input="syncToLivewire()"
                                           placeholder="e.g. step1_output, step2_output, api_response"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Aggregation mode</label>
                                    <select x-model="selectedNode.mode" @change="syncToLivewire()"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="concat">Concatenate (text)</option>
                                        <option value="array">Collect into array</option>
                                        <option value="merge">Merge (JSON objects)</option>
                                        <option value="sum">Sum (numeric)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Output variable name</label>
                                    <input type="text" x-model="selectedNode.output_variable" @input="syncToLivewire()"
                                           placeholder="e.g. aggregated"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div class="rounded-lg bg-cyan-50 p-3 text-xs text-cyan-700">
                                    Combines multiple workflow variables into a single output. Useful after parallel branches or iterative steps.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'template_transform'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Template</label>
                                    <textarea x-model="selectedNode.template" @input="syncToLivewire()" rows="5"
                                              placeholder="Use {{variable}} placeholders from prior steps."
                                              class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-mono focus:border-primary-500 focus:ring-primary-500"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Output variable name</label>
                                    <input type="text" x-model="selectedNode.output_variable" @input="syncToLivewire()"
                                           placeholder="e.g. formatted_output"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div class="rounded-lg bg-lime-50 p-3 text-xs text-lime-700">
                                    Renders a Mustache-style template using workflow variables. No LLM call — pure string interpolation.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'knowledge_retrieval'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Query</label>
                                    <textarea x-model="selectedNode.query" @input="syncToLivewire()" rows="2"
                                              placeholder="Search query or {{variable}} from prior step"
                                              class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Top K results</label>
                                    <input type="number" x-model.number="selectedNode.top_k" @input="syncToLivewire()"
                                           min="1" max="20" placeholder="5"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Source</label>
                                    <select x-model="selectedNode.source" @change="syncToLivewire()"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        <option value="memory">Memory Bank</option>
                                        <option value="knowledge_graph">Knowledge Graph</option>
                                        <option value="both">Both</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Output variable name</label>
                                    <input type="text" x-model="selectedNode.output_variable" @input="syncToLivewire()"
                                           placeholder="e.g. retrieved_context"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div class="rounded-lg bg-indigo-50 p-3 text-xs text-indigo-700">
                                    Performs semantic search over Memory Bank and/or Knowledge Graph. Injects retrieved context as a workflow variable.
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedNode.type === 'boruna_step'">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Step name</label>
                                    <input type="text" x-model="selectedNode.step_name" @input="syncToLivewire()"
                                           placeholder="Descriptive step identifier"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Action</label>
                                    <input type="text" x-model="selectedNode.action" @input="syncToLivewire()"
                                           placeholder="e.g. evaluate, score, rank"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Config (JSON)</label>
                                    <textarea x-model="selectedNode.config" @input="syncToLivewire()" rows="4"
                                              placeholder="{}"
                                              class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-mono focus:border-primary-500 focus:ring-primary-500"></textarea>
                                </div>
                                <div class="rounded-lg bg-fuchsia-50 p-3 text-xs text-fuchsia-700">
                                    Custom Boruna pipeline step. Executes a named action with JSON configuration within the experiment pipeline.
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
Alpine.data('workflowBuilder', (initialNodes, initialEdges, agents, skills, crews, workflows) => ({
    localNodes: initialNodes,
    localEdges: initialEdges,
    agents: agents,
    skills: skills,
    crews: crews || [],
    workflows: workflows || [],

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

    // Execution overlay state
    stepStatuses: {},
    isExecutionMode: false,

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
        if (this.isExecutionMode) return;
        this.nodeCounter++;
        const id = 'node-' + Date.now() + '-' + this.nodeCounter;
        const labels = { agent: 'Agent ' + this.nodeCounter, crew: 'Crew ' + this.nodeCounter, conditional: 'Condition', human_task: 'Human Task ' + this.nodeCounter, switch: 'Switch', do_while: 'Do While', dynamic_fork: 'Dynamic Fork', time_gate: 'Time Gate', merge: 'Merge', sub_workflow: 'Sub-Workflow', end: 'End' };

        this.localNodes.push({
            id: id,
            type: type,
            label: labels[type] || type,
            agent_id: null,
            skill_id: null,
            crew_id: null,
            sub_workflow_id: null,
            config: {},
            position_x: 250 + (this.nodeCounter * 20) % 200,
            position_y: 150 + Math.floor(this.nodeCounter / 3) * 100,
            order: this.nodeCounter,
        });

        this.syncToLivewireNow();
    },

    removeNode(nodeId) {
        if (this.isExecutionMode) return;
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
        const labels = { start: 'Start', end: 'End', agent: 'Agent', crew: 'Crew', conditional: 'Condition', human_task: 'Human Task', switch: 'Switch', dynamic_fork: 'Dynamic Fork', do_while: 'Do While', time_gate: 'Time Gate', merge: 'Merge', sub_workflow: 'Sub-Workflow' };
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

        // Watch for execution status updates from Livewire
        this.$wire.watch('stepStatuses', (value) => {
            this.stepStatuses = value || {};
        });
        this.$wire.watch('executionMode', (value) => {
            this.isExecutionMode = value || false;
        });

        // WebMCP imperative tools for browser AI agents
        if (window.FleetQWebMcp?.isAvailable()) {
            this._registerWebMcpTools();
        }
    },

    _registerWebMcpTools() {
        const self = this;
        const reg = window.FleetQWebMcp.registerTool;

        reg({
            name: 'workflow_add_node',
            description: 'Add a node to the workflow graph being edited.',
            inputSchema: {
                type: 'object',
                properties: {
                    type: { type: 'string', enum: ['agent','crew','conditional','human_task','switch','do_while','dynamic_fork','time_gate','merge','sub_workflow','end'], description: 'Node type' },
                    label: { type: 'string', description: 'Display label for the node' },
                },
                required: ['type']
            },
            annotations: { readOnlyHint: false },
            execute: async ({ type, label }) => {
                self.addNode(type);
                if (label) {
                    const node = self.localNodes[self.localNodes.length - 1];
                    if (node) node.label = label;
                }
                return { content: [{ type: 'text', text: JSON.stringify({ success: true, node_count: self.localNodes.length }) }] };
            }
        });

        reg({
            name: 'workflow_remove_node',
            description: 'Remove a node from the workflow graph.',
            inputSchema: {
                type: 'object',
                properties: { node_id: { type: 'string', description: 'ID of the node to remove' } },
                required: ['node_id']
            },
            annotations: { readOnlyHint: false },
            execute: async ({ node_id }) => {
                self.removeNode(node_id);
                return { content: [{ type: 'text', text: 'Node removed.' }] };
            }
        });

        reg({
            name: 'workflow_connect_nodes',
            description: 'Connect two nodes with a directed edge.',
            inputSchema: {
                type: 'object',
                properties: {
                    source_node_id: { type: 'string', description: 'Source node ID' },
                    target_node_id: { type: 'string', description: 'Target node ID' },
                    condition: { type: 'string', description: 'Optional condition expression for the edge' },
                },
                required: ['source_node_id', 'target_node_id']
            },
            annotations: { readOnlyHint: false },
            execute: async ({ source_node_id, target_node_id, condition }) => {
                const edgeId = 'edge-' + Date.now();
                self.localEdges.push({
                    id: edgeId,
                    source_node_id,
                    target_node_id,
                    condition: condition || null,
                    case_value: null,
                });
                self.syncToLivewireNow();
                return { content: [{ type: 'text', text: JSON.stringify({ success: true, edge_id: edgeId }) }] };
            }
        });

        reg({
            name: 'workflow_get_graph',
            description: 'Get the current workflow graph state (nodes and edges).',
            inputSchema: { type: 'object', properties: {} },
            annotations: { readOnlyHint: true },
            execute: async () => {
                return { content: [{ type: 'text', text: JSON.stringify({ nodes: self.localNodes, edges: self.localEdges }) }] };
            }
        });

        reg({
            name: 'workflow_save_graph',
            description: 'Save the current workflow graph to the server.',
            inputSchema: { type: 'object', properties: {} },
            annotations: { readOnlyHint: false },
            execute: async (_, client) => {
                if (client?.requestUserInteraction) {
                    await client.requestUserInteraction(async () => true);
                }
                await $wire.save();
                return { content: [{ type: 'text', text: 'Graph saved.' }] };
            }
        });

        reg({
            name: 'workflow_validate_graph',
            description: 'Validate the current workflow graph for errors.',
            inputSchema: { type: 'object', properties: {} },
            annotations: { readOnlyHint: true },
            execute: async () => {
                await $wire.validateGraph();
                return { content: [{ type: 'text', text: 'Validation triggered — check the page for results.' }] };
            }
        });

        // Cleanup on component destroy
        this.$cleanup = () => {
            ['workflow_add_node','workflow_remove_node','workflow_connect_nodes',
             'workflow_get_graph','workflow_save_graph','workflow_validate_graph']
                .forEach(n => window.FleetQWebMcp?.unregisterTool(n));
        };
    },
}));
</script>
@endscript
