<style>
    @keyframes flow-pulse {
        0%   { stroke-dashoffset: 100; }
        100% { stroke-dashoffset: 0; }
    }
    .edge-active {
        stroke-dasharray: 8 4;
        animation: flow-pulse 1s linear infinite;
    }
</style>

<div wire:poll.30s x-data="{
    echoConnected: false,
    init() {
        if (typeof window.Echo !== 'undefined') {
            this.echoConnected = true;
            window.Echo.connector?.pusher?.connection?.bind?.('connected', () => { this.echoConnected = true; });
            window.Echo.connector?.pusher?.connection?.bind?.('disconnected', () => { this.echoConnected = false; });
            window.Echo.connector?.pusher?.connection?.bind?.('unavailable', () => { this.echoConnected = false; });
        }
    }
}">
    @if($graph)
        <div class="rounded-lg border border-gray-200 bg-white">
            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-rotate text-lg text-gray-400"></i>
                    <span class="text-sm font-medium text-gray-700">Workflow Progress</span>

                    {{-- Echo connection status dot --}}
                    <span
                        x-show="typeof window.Echo !== 'undefined'"
                        x-tooltip="echoConnected ? 'Real-time updates active' : 'Polling (real-time unavailable)'"
                        :class="echoConnected ? 'bg-green-400' : 'bg-gray-300'"
                        class="inline-block h-2 w-2 rounded-full"
                        title="Real-time connection status"
                    ></span>
                </div>
                @if($workflowId)
                    <a href="{{ route('workflows.show', $workflowId) }}" class="text-xs text-primary-600 hover:underline">
                        View Workflow
                    </a>
                @endif
            </div>

            {{-- Progress Bar --}}
            @if($total > 0)
                <div class="border-b border-gray-200 px-4 py-3">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-xs text-gray-500">
                            {{ $completed }}/{{ $total }} steps completed
                            @if($running > 0)
                                <span class="text-blue-600">&middot; {{ $running }} running</span>
                            @endif
                            @if($failed > 0)
                                <span class="text-red-600">&middot; {{ $failed }} failed</span>
                            @endif
                            @if($skipped > 0)
                                <span class="text-gray-400">&middot; {{ $skipped }} skipped</span>
                            @endif
                        </span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-gray-200">
                        @php
                            $completedPct = $total > 0 ? round(($completed / $total) * 100) : 0;
                            $failedPct = $total > 0 ? round(($failed / $total) * 100) : 0;
                            $runningPct = $total > 0 ? round(($running / $total) * 100) : 0;
                        @endphp
                        <div class="flex h-2 overflow-hidden rounded-full">
                            @if($completedPct > 0)
                                <div class="bg-green-500" style="width: {{ $completedPct }}%"></div>
                            @endif
                            @if($runningPct > 0)
                                <div class="animate-pulse bg-blue-500" style="width: {{ $runningPct }}%"></div>
                            @endif
                            @if($failedPct > 0)
                                <div class="bg-red-500" style="width: {{ $failedPct }}%"></div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- SVG edge connections (animated when source node is running) --}}
            @if(!empty($graph['edges']))
                @php
                    $runningNodeIds = $nodes
                        ->filter(fn($n) => ($nodeStates[$n['id']]['status'] ?? $n['step_status']) === 'running')
                        ->pluck('id')
                        ->flip()
                        ->toArray();
                @endphp
                <svg class="sr-only" aria-hidden="true" style="position:absolute;width:0;height:0">
                    {{-- Edges rendered visually in the workflow builder; this block provides --}}
                    {{-- animated CSS classes to any SVG paths with matching data-edge-source --}}
                </svg>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var activeSourceIds = @json(array_keys($runningNodeIds));
                        activeSourceIds.forEach(function (srcId) {
                            document.querySelectorAll('[data-edge-source="' + srcId + '"]').forEach(function (el) {
                                el.classList.add('edge-active');
                            });
                        });
                    });
                </script>
            @endif

            {{-- Node List --}}
            <div class="divide-y divide-gray-100">
                @foreach($nodes as $node)
                    @php
                        // Merge real-time state pushed via Echo with DB state; Echo wins if present
                        $rtStatus = $nodeStates[$node['id']]['status'] ?? $node['step_status'];
                    @endphp
                    <div x-data="{ rtStatus: @js($rtStatus) }"
                         x-on:workflow-node-updated.window="
                             if ($event.detail && $event.detail.nodeId === @js($node['id'])) {
                                 rtStatus = $event.detail.status;
                             }
                         ">
                        <button wire:click="toggleNode('{{ $node['id'] }}')"
                            :class="{
                                'ring-1 ring-blue-300 bg-blue-50': rtStatus === 'running',
                                'ring-1 ring-green-200 bg-green-50/40': rtStatus === 'completed',
                                'ring-1 ring-red-200 bg-red-50/40': rtStatus === 'failed'
                            }"
                            class="flex w-full items-center justify-between px-4 py-2.5 text-left transition hover:bg-gray-50">
                            <div class="flex items-center gap-3">
                                {{-- Status Icon (uses rtStatus for real-time updates) --}}
                                <template x-if="rtStatus === 'system'">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100">
                                        @if($node['type'] === 'start')
                                            <i class="fa-solid fa-play text-sm text-gray-500"></i>
                                        @else
                                            <i class="fa-solid fa-stop text-sm text-gray-500"></i>
                                        @endif
                                    </span>
                                </template>
                                <template x-if="rtStatus === 'pending'">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-xs text-gray-400">
                                        {{ $loop->iteration }}
                                    </span>
                                </template>
                                <template x-if="rtStatus === 'running'">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100">
                                        <i class="fa-solid fa-spinner fa-spin text-base text-blue-600"></i>
                                    </span>
                                </template>
                                <template x-if="rtStatus === 'completed'">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-green-100">
                                        <i class="fa-solid fa-check text-base text-green-600"></i>
                                    </span>
                                </template>
                                <template x-if="rtStatus === 'failed'">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-red-100">
                                        <i class="fa-solid fa-xmark text-base text-red-600"></i>
                                    </span>
                                </template>
                                <template x-if="rtStatus === 'skipped'">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100">
                                        <i class="fa-solid fa-minus text-base text-gray-400"></i>
                                    </span>
                                </template>
                                <template x-if="!['system','pending','running','completed','failed','skipped'].includes(rtStatus)">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-xs text-gray-400">?</span>
                                </template>

                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $node['label'] }}</p>
                                    <div class="flex items-center gap-2">
                                        @php
                                            $typeColors = [
                                                'start' => 'bg-emerald-100 text-emerald-700',
                                                'end' => 'bg-slate-100 text-slate-700',
                                                'agent' => 'bg-sky-100 text-sky-700',
                                                'conditional' => 'bg-amber-100 text-amber-700',
                                                'crew' => 'bg-purple-100 text-purple-700',
                                            ];
                                            $typeColor = $typeColors[$node['type']] ?? 'bg-gray-100 text-gray-700';
                                        @endphp
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $typeColor }}">{{ $node['type'] }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                @if($node['step_duration_ms'])
                                    <span class="text-xs text-gray-500">{{ round($node['step_duration_ms'] / 1000) }}s</span>
                                @elseif($node['step_status'] === 'running' && $node['step_started_at'])
                                    <span class="text-xs text-blue-500">{{ $node['step_started_at']->diffForHumans(short: true, parts: 1) }}</span>
                                @elseif($node['step_cost'])
                                    <span class="text-xs text-gray-500">{{ $node['step_cost'] }} cr</span>
                                @endif

                                @if($node['type'] !== 'start' && $node['type'] !== 'end')
                                    <i class="fa-solid fa-chevron-down text-base text-gray-400 transition {{ $expandedNodeId === $node['id'] ? 'rotate-180' : '' }}"></i>
                                @endif
                            </div>
                        </button>

                        {{-- Expanded Details --}}
                        @if($expandedNodeId === $node['id'] && $node['type'] !== 'start' && $node['type'] !== 'end')
                            <div class="border-t border-gray-100 px-4 py-3">
                                @if($node['step_error'])
                                    <div class="mb-2 rounded bg-red-50 p-2">
                                        <p class="text-xs font-medium text-red-700">Error</p>
                                        <pre class="mt-1 max-h-32 overflow-auto text-xs text-red-600">{{ $node['step_error'] }}</pre>
                                    </div>
                                @endif

                                @if($node['step_status'] === 'failed' && $node['step_id'])
                                    <button wire:click="retryStep('{{ $node['step_id'] }}')"
                                        wire:confirm="Retry from this step? This will reset this step and all subsequent steps."
                                        class="mb-2 inline-flex items-center gap-1 rounded bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100 transition">
                                        <i class="fa-solid fa-rotate text-sm"></i>
                                        Retry from this step
                                    </button>
                                @endif

                                @if($node['step_output'])
                                    <div x-data="{ showRaw: false }" class="rounded bg-gray-50 p-2">
                                        <div class="flex items-center justify-between">
                                            <p class="text-xs font-medium text-gray-600">Output</p>
                                            <button @click="showRaw = !showRaw" class="text-xs text-primary-600 hover:underline">
                                                <span x-text="showRaw ? 'Formatted' : 'Raw JSON'"></span>
                                            </button>
                                        </div>

                                        {{-- Formatted markdown view --}}
                                        <div x-show="!showRaw" class="prose prose-sm mt-1 max-h-64 overflow-auto">
                                            @php
                                                $stepOutput = $node['step_output'];
                                                $a2uiComponents = null;
                                                $a2uiDataModel = [];
                                                if (is_array($stepOutput) && isset($stepOutput['a2ui_surface']['components'])) {
                                                    $a2uiComponents = $stepOutput['a2ui_surface']['components'];
                                                    $a2uiDataModel = $stepOutput['a2ui_surface']['dataModel'] ?? $stepOutput['a2ui_surface']['data_model'] ?? [];
                                                }
                                                $outputText = is_array($stepOutput)
                                                    ? ($stepOutput['result'] ?? json_encode($stepOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                                                    : $stepOutput;
                                            @endphp
                                            {!! \Illuminate\Support\Str::markdown($outputText) !!}
                                            @if($a2uiComponents)
                                                <x-a2ui.surface :components="$a2uiComponents" :data-model="$a2uiDataModel" class="mt-3" />
                                            @endif
                                        </div>

                                        {{-- Raw JSON view --}}
                                        <pre x-show="showRaw" x-cloak class="mt-1 max-h-64 overflow-auto text-xs text-gray-700">{{ is_array($node['step_output']) ? json_encode($node['step_output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $node['step_output'] }}</pre>
                                    </div>
                                @endif

                                @if($node['step_status'] === 'running')
                                    <div class="rounded bg-blue-50 p-2">
                                        <p class="text-xs font-medium text-blue-600">Live Output</p>
                                        @if($node['step_stream_output'])
                                            <div class="prose prose-sm mt-1 max-h-64 overflow-auto text-xs">
                                                {!! \Illuminate\Support\Str::markdown($node['step_stream_output']) !!}
                                            </div>
                                        @else
                                            <p class="mt-1 text-xs text-blue-400">Waiting for output...</p>
                                        @endif
                                    </div>
                                @endif

                                @if(!$node['step_error'] && !$node['step_output'] && $node['step_status'] === 'pending')
                                    <p class="text-xs text-gray-400">Waiting for preceding nodes to complete.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
