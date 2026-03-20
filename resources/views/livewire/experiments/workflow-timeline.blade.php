<div>
    @if($snapshots->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-6 text-center text-sm text-gray-500">
            No execution snapshots recorded for this experiment.
        </div>
    @else
        <div class="flex gap-4">
            {{-- Timeline list --}}
            <div class="w-1/2 space-y-1">
                @foreach($snapshots as $snapshot)
                    <button
                        wire:click="selectSnapshot('{{ $snapshot->id }}')"
                        class="flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-left text-sm transition
                            {{ ($selectedSnapshot['id'] ?? null) === $snapshot->id
                                ? 'border-primary-300 bg-primary-50'
                                : 'border-gray-200 bg-white hover:bg-gray-50' }}"
                    >
                        {{-- Event icon --}}
                        <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full
                            {{ match($snapshot->event_type) {
                                'step_started' => 'bg-blue-100 text-blue-600',
                                'step_completed' => 'bg-green-100 text-green-600',
                                'step_failed' => 'bg-red-100 text-red-600',
                                'condition_evaluated' => 'bg-yellow-100 text-yellow-600',
                                'loop_iteration' => 'bg-cyan-100 text-cyan-600',
                                'human_decision' => 'bg-indigo-100 text-indigo-600',
                                'agent_handoff' => 'bg-purple-100 text-purple-600',
                                default => 'bg-gray-100 text-gray-600',
                            } }}">
                            <span class="text-xs font-bold">{{ $snapshot->sequence }}</span>
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-900">{{ str_replace('_', ' ', $snapshot->event_type) }}</span>
                                <span class="text-xs text-gray-400">+{{ number_format($snapshot->duration_from_start_ms) }}ms</span>
                            </div>
                            @if($snapshot->metadata)
                                <div class="truncate text-xs text-gray-500">
                                    {{ \Illuminate\Support\Str::limit(json_encode($snapshot->metadata), 60) }}
                                </div>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>

            {{-- Snapshot detail panel --}}
            <div class="w-1/2">
                @if($selectedSnapshot)
                    <div class="rounded-lg border border-gray-200 bg-white">
                        <div class="border-b border-gray-200 px-4 py-3">
                            <h4 class="text-sm font-semibold text-gray-900">
                                Snapshot #{{ $selectedSnapshot['sequence'] }} — {{ str_replace('_', ' ', $selectedSnapshot['event_type']) }}
                            </h4>
                            <p class="text-xs text-gray-500">{{ $selectedSnapshot['created_at'] }}</p>
                        </div>

                        <div class="divide-y divide-gray-100">
                            {{-- Graph State --}}
                            <div class="px-4 py-3">
                                <h5 class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Graph State</h5>
                                <pre class="max-h-48 overflow-auto rounded bg-gray-50 p-2 text-xs text-gray-700">{{ json_encode($selectedSnapshot['graph_state'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>

                            {{-- Step Input --}}
                            @if($selectedSnapshot['step_input'])
                                <div class="px-4 py-3">
                                    <h5 class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Step Input</h5>
                                    <pre class="max-h-32 overflow-auto rounded bg-gray-50 p-2 text-xs text-gray-700">{{ json_encode($selectedSnapshot['step_input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif

                            {{-- Step Output --}}
                            @if($selectedSnapshot['step_output'])
                                <div class="px-4 py-3">
                                    <h5 class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Step Output</h5>
                                    <pre class="max-h-32 overflow-auto rounded bg-gray-50 p-2 text-xs text-gray-700">{{ json_encode($selectedSnapshot['step_output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif

                            {{-- Metadata --}}
                            @if($selectedSnapshot['metadata'])
                                <div class="px-4 py-3">
                                    <h5 class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Metadata</h5>
                                    <pre class="max-h-32 overflow-auto rounded bg-gray-50 p-2 text-xs text-gray-700">{{ json_encode($selectedSnapshot['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="flex h-full items-center justify-center rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-400">
                        Select a snapshot to inspect
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
