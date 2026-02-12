<div wire:poll.2s>
    @if($graph)
        <div class="rounded-lg border border-gray-200 bg-white">
            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span class="text-sm font-medium text-gray-700">Workflow Progress</span>
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

            {{-- Node List --}}
            <div class="divide-y divide-gray-100">
                @foreach($nodes as $node)
                    <div>
                        <button wire:click="toggleNode('{{ $node['id'] }}')"
                            class="flex w-full items-center justify-between px-4 py-2.5 text-left transition hover:bg-gray-50">
                            <div class="flex items-center gap-3">
                                {{-- Status Icon --}}
                                @switch($node['step_status'])
                                    @case('system')
                                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100">
                                            @if($node['type'] === 'start')
                                                <svg class="h-3.5 w-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                                </svg>
                                            @else
                                                <svg class="h-3.5 w-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                            @endif
                                        </span>
                                        @break
                                    @case('pending')
                                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-xs text-gray-400">
                                            {{ $node['order'] ?? '-' }}
                                        </span>
                                        @break
                                    @case('running')
                                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100">
                                            <svg class="h-4 w-4 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                        </span>
                                        @break
                                    @case('completed')
                                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-green-100">
                                            <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </span>
                                        @break
                                    @case('failed')
                                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-red-100">
                                            <svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </span>
                                        @break
                                    @default
                                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-xs text-gray-400">?</span>
                                @endswitch

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
                                @elseif($node['step_cost'])
                                    <span class="text-xs text-gray-500">{{ $node['step_cost'] }} cr</span>
                                @endif

                                @if($node['type'] !== 'start' && $node['type'] !== 'end')
                                    <svg class="h-4 w-4 text-gray-400 transition {{ $expandedNodeId === $node['id'] ? 'rotate-180' : '' }}"
                                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
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

                                @if($node['step_output'])
                                    <div class="rounded bg-gray-50 p-2">
                                        <p class="text-xs font-medium text-gray-600">Output</p>
                                        <pre class="mt-1 max-h-48 overflow-auto text-xs text-gray-700">{{ is_array($node['step_output']) ? json_encode($node['step_output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $node['step_output'] }}</pre>
                                    </div>
                                @endif

                                @if(!$node['step_error'] && !$node['step_output'] && $node['step_status'] === 'running')
                                    <p class="text-xs text-blue-500">Node is currently executing...</p>
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
