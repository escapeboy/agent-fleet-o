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
                        <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">
                                    Snapshot #{{ $selectedSnapshot['sequence'] }} — {{ str_replace('_', ' ', $selectedSnapshot['event_type']) }}
                                </h4>
                                <p class="text-xs text-gray-500">{{ $selectedSnapshot['created_at'] }}</p>
                            </div>
                            @if(!empty($selectedSnapshot['playbook_step_id']))
                                <button
                                    type="button"
                                    wire:click="openReplay('{{ $selectedSnapshot['id'] }}')"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                                    title="Run this step's agent against custom input without persisting an execution"
                                >
                                    <i class="fa-solid fa-flask"></i>
                                    {{ __('Replay…') }}
                                </button>
                            @endif
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
                                    @php
                                        $timelineOutput = $selectedSnapshot['step_output'];
                                        $timelineA2ui = is_array($timelineOutput) && isset($timelineOutput['a2ui_surface']['components'])
                                            ? $timelineOutput['a2ui_surface'] : null;
                                    @endphp
                                    @if($timelineA2ui)
                                        <x-a2ui.surface
                                            :components="$timelineA2ui['components']"
                                            :data-model="$timelineA2ui['dataModel'] ?? $timelineA2ui['data_model'] ?? []"
                                            class="mb-2"
                                        />
                                    @endif
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

    {{-- Replay modal — appears when openReplay() has populated $replayingFor --}}
    @if($replayingFor !== '')
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            wire:click.self="closeReplay"
            x-data
            @keydown.escape.window="$wire.closeReplay()"
        >
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-2xl">
                <div class="flex items-start justify-between border-b border-gray-200 px-5 py-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Replay snapshot with overrides') }}</h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ __('Runs the agent against your input WITHOUT persisting any execution row, artifact, or AiRun. Costs LLM credits.') }}
                        </p>
                    </div>
                    <button type="button" wire:click="closeReplay" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="space-y-3 px-5 py-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-700">{{ __('Input message') }}</label>
                        <textarea
                            wire:model.live="replayInput"
                            rows="4"
                            class="block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm focus:border-primary-500 focus:ring-primary-500"
                            placeholder="What should the agent respond to?"
                        ></textarea>
                    </div>

                    <div x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="text-xs text-gray-600 hover:text-gray-900">
                            <i class="fa-solid fa-caret-right" x-show="!open"></i>
                            <i class="fa-solid fa-caret-down" x-show="open"></i>
                            {{ __('System prompt override (optional)') }}
                        </button>
                        <textarea
                            x-show="open"
                            wire:model.live="replaySystemPromptOverride"
                            rows="6"
                            class="mt-2 block w-full rounded-lg border border-gray-300 bg-white p-2 font-mono text-xs focus:border-primary-500 focus:ring-primary-500"
                            placeholder="Leave blank to use the agent's saved system prompt."
                        ></textarea>
                    </div>

                    @if($replayError !== '')
                        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                            {{ $replayError }}
                        </div>
                    @endif

                    @if($replayResult)
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                            <div class="mb-2 flex items-center justify-between text-xs text-emerald-800">
                                <span>
                                    {{ $replayResult['model'] ?? '' }} · {{ $replayResult['latency_ms'] ?? 0 }}ms ·
                                    {{ ($replayResult['tokens_input'] ?? 0) }}+{{ ($replayResult['tokens_output'] ?? 0) }} tokens ·
                                    {{ ($replayResult['cost_credits'] ?? 0) }} credits
                                </span>
                            </div>
                            <pre class="max-h-64 overflow-auto whitespace-pre-wrap text-sm text-emerald-900">{{ $replayResult['output'] ?? '' }}</pre>
                        </div>
                    @endif

                    @php
                        $priorReplays = collect(($selectedSnapshot['metadata'] ?? [])['replays'] ?? []);
                        // Drop the most recent if it matches the current $replayResult
                        // to avoid showing the same output twice in the modal.
                        if ($replayResult && $priorReplays->isNotEmpty()) {
                            $latest = $priorReplays->first();
                            if (($latest['output'] ?? '') === ($replayResult['output'] ?? '')) {
                                $priorReplays = $priorReplays->slice(1);
                            }
                        }
                    @endphp
                    @if($priorReplays->isNotEmpty())
                        <div x-data="{ open: false }" class="text-xs">
                            <button type="button" @click="open = !open" class="text-gray-600 hover:text-gray-900">
                                <i class="fa-solid fa-caret-right" x-show="!open"></i>
                                <i class="fa-solid fa-caret-down" x-show="open"></i>
                                {{ __('Prior replays') }} ({{ $priorReplays->count() }})
                            </button>
                            <div x-show="open" class="mt-2 space-y-2">
                                @foreach($priorReplays as $prior)
                                    <div class="rounded-lg border border-gray-200 bg-white p-2">
                                        <div class="mb-1 text-[10px] uppercase tracking-wide text-gray-400">
                                            {{ $prior['at'] ?? '' }} · {{ $prior['model'] ?? '' }} ·
                                            {{ $prior['latency_ms'] ?? 0 }}ms · {{ $prior['cost_credits'] ?? 0 }}cr
                                            @if($prior['override_used'] ?? false)
                                                · {{ __('override') }}
                                            @endif
                                        </div>
                                        <pre class="max-h-40 overflow-auto whitespace-pre-wrap text-xs text-gray-800">{{ $prior['output'] ?? '' }}</pre>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <button
                        type="button"
                        wire:click="closeReplay"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        {{ __('Close') }}
                    </button>
                    <button
                        type="button"
                        wire:click="executeReplay"
                        wire:loading.attr="disabled"
                        wire:target="executeReplay"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="executeReplay">
                            <i class="fa-solid fa-flask"></i>
                            {{ __('Replay') }}
                        </span>
                        <span wire:loading wire:target="executeReplay">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                            {{ __('Replaying...') }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
