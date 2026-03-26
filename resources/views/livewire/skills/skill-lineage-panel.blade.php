<div class="mt-4">
    <button
        wire:click="togglePanel"
        class="flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform {{ $showPanel ? 'rotate-90' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
        Version Lineage
        @if(count($lineageData['nodes'] ?? []) > 0)
            <span class="text-xs text-gray-400">({{ count($lineageData['nodes']) }} versions)</span>
        @endif
    </button>

    @if($showPanel)
        <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
            @if(empty($lineageData['nodes']))
                <p class="text-sm text-gray-400">No version history available.</p>
            @else
                {{-- Alpine.js DAG visualization --}}
                <div
                    x-data="{
                        nodes: {{ json_encode($lineageData['nodes']) }},
                        edges: {{ json_encode($lineageData['edges']) }},
                        selectedNode: null,
                        selectNode(node) {
                            this.selectedNode = this.selectedNode?.id === node.id ? null : node;
                        },
                        typeColor(type) {
                            const colors = {
                                fix: 'bg-yellow-100 border-yellow-400 text-yellow-800',
                                derived: 'bg-blue-100 border-blue-400 text-blue-800',
                                captured: 'bg-green-100 border-green-400 text-green-800',
                                initial: 'bg-purple-100 border-purple-400 text-purple-800',
                                manual: 'bg-gray-100 border-gray-400 text-gray-800',
                            };
                            return colors[type] || colors.manual;
                        }
                    }"
                    class="space-y-2"
                >
                    {{-- Node list (linear display for simplicity) --}}
                    <div class="flex flex-wrap gap-2">
                        <template x-for="node in nodes" :key="node.id">
                            <div
                                @click="selectNode(node)"
                                :class="[
                                    'cursor-pointer px-3 py-1.5 rounded-full border text-xs font-medium transition-all',
                                    typeColor(node.evolution_type),
                                    selectedNode?.id === node.id ? 'ring-2 ring-offset-1 ring-primary-500' : ''
                                ]"
                            >
                                <span x-text="'v' + node.version"></span>
                                <span class="ml-1 opacity-60" x-text="node.evolution_type"></span>
                            </div>
                        </template>
                    </div>

                    {{-- Edge count summary --}}
                    @if(count($lineageData['edges']) > 0)
                        <p class="mt-1 text-xs text-gray-400">
                            {{ count($lineageData['edges']) }} evolution{{ count($lineageData['edges']) > 1 ? 's' : '' }} in lineage
                        </p>
                    @endif

                    {{-- Selected node detail panel --}}
                    <div x-show="selectedNode !== null" x-cloak class="mt-3 rounded-lg border border-gray-200 bg-white p-3 text-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="font-medium" x-text="'Version ' + selectedNode?.version"></span>
                            <span
                                class="rounded-full border px-2 py-0.5 text-xs"
                                :class="typeColor(selectedNode?.evolution_type)"
                                x-text="selectedNode?.evolution_type"
                            ></span>
                        </div>
                        <p class="text-xs text-gray-500" x-text="selectedNode?.created_at"></p>
                        <template x-if="selectedNode?.changelog">
                            <div class="mt-2">
                                <p class="mb-1 text-xs font-medium text-gray-700">Changes:</p>
                                <p class="whitespace-pre-wrap font-mono text-xs text-gray-600" x-text="selectedNode?.changelog"></p>
                            </div>
                        </template>
                        <template x-if="!selectedNode?.changelog">
                            <p class="mt-1 text-xs italic text-gray-400">No changelog recorded.</p>
                        </template>
                    </div>

                    {{-- Legend --}}
                    <div class="mt-2 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-2">
                        <span class="text-xs text-gray-400">Legend:</span>
                        <span class="rounded-full border border-purple-400 bg-purple-100 px-2 py-0.5 text-xs text-purple-800">initial</span>
                        <span class="rounded-full border border-yellow-400 bg-yellow-100 px-2 py-0.5 text-xs text-yellow-800">fix</span>
                        <span class="rounded-full border border-blue-400 bg-blue-100 px-2 py-0.5 text-xs text-blue-800">derived</span>
                        <span class="rounded-full border border-green-400 bg-green-100 px-2 py-0.5 text-xs text-green-800">captured</span>
                        <span class="rounded-full border border-gray-400 bg-gray-100 px-2 py-0.5 text-xs text-gray-800">manual</span>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
