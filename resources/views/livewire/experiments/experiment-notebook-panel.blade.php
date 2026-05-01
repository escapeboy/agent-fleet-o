<div class="space-y-6">
    @if($stages->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center text-sm text-gray-500">
            No stages recorded yet. Stages appear as the experiment runs.
        </div>
    @endif

    @foreach($stages as $index => $stage)
        @php
            $runs = $stageRuns[$stage->id] ?? collect();
            $totalTokens = $runs->sum('prompt_tokens') + $runs->sum('completion_tokens');
            $totalCost = $runs->sum('cost_credits');
            $durationSec = $stage->duration_ms ? round($stage->duration_ms / 1000, 1) : null;
            $isEditing = $editingStageId === $stage->id;
        @endphp

        {{-- Notebook cell --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm" id="stage-{{ $stage->id }}">

            {{-- Cell header --}}
            <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-4 py-2.5 rounded-t-xl">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-gray-200 text-xs font-semibold text-gray-600">
                        {{ $index + 1 }}
                    </span>
                    <span class="text-sm font-medium text-gray-800 capitalize">{{ str_replace('_', ' ', $stage->stage->value) }}</span>
                    <span class="text-xs text-gray-500">Iteration {{ $stage->iteration }}</span>
                    <x-status-badge :status="$stage->status->value" />
                </div>
                <div class="flex items-center gap-3 text-xs text-gray-400">
                    @if($durationSec)
                        <span title="Duration">{{ $durationSec }}s</span>
                    @endif
                    @if($totalTokens > 0)
                        <span title="Tokens used">{{ number_format($totalTokens) }} tok</span>
                    @endif
                    @if($totalCost > 0)
                        <span title="Cost in credits">{{ number_format($totalCost, 2) }}¢</span>
                    @endif
                    @if($stage->started_at)
                        <span title="Started at">{{ $stage->started_at->format('H:i:s') }}</span>
                    @endif
                    <a href="#stage-{{ $stage->id }}" class="text-gray-300 hover:text-gray-500" title="Permalink to this cell">
                        <i class="fa-solid fa-link text-xs"></i>
                    </a>
                </div>
            </div>

            {{-- Annotation block --}}
            <div class="border-b border-gray-100 px-4 py-3">
                @if($isEditing)
                    <div class="space-y-2" wire:key="annotation-editor-{{ $stage->id }}">
                        <textarea
                            wire:model="pendingAnnotations.{{ $stage->id }}"
                            rows="3"
                            placeholder="Add a note about this stage…"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
                        ></textarea>
                        <div class="flex gap-2">
                            <button wire:click="saveAnnotation('{{ $stage->id }}')" class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                                Save note
                            </button>
                            <button wire:click="cancelEditing" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                                Cancel
                            </button>
                        </div>
                    </div>
                @elseif($stage->annotation)
                    <div class="group flex items-start gap-2">
                        <p class="flex-1 text-sm text-gray-600 whitespace-pre-wrap">{{ $stage->annotation }}</p>
                        <button wire:click="startEditing('{{ $stage->id }}')" class="invisible shrink-0 text-gray-300 group-hover:visible hover:text-gray-500" title="Edit note">
                            <i class="fa-solid fa-pen text-xs"></i>
                        </button>
                    </div>
                @else
                    <button wire:click="startEditing('{{ $stage->id }}')" class="text-xs text-gray-400 hover:text-gray-600 italic">
                        + Add a note…
                    </button>
                @endif
            </div>

            {{-- Input snapshot --}}
            @if(!empty($stage->input_snapshot))
                <details class="group border-b border-gray-100">
                    <summary class="flex cursor-pointer items-center gap-2 px-4 py-2.5 text-xs font-medium text-gray-500 hover:bg-gray-50">
                        <i class="fa-solid fa-arrow-right-to-bracket fa-fw text-blue-400"></i>
                        Input
                        <i class="fa-solid fa-chevron-down ml-auto text-gray-300 transition-transform group-open:rotate-180"></i>
                    </summary>
                    <div class="px-4 pb-3">
                        <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100">{{ json_encode($stage->input_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </details>
            @endif

            {{-- LLM run blocks --}}
            @if($runs->isNotEmpty())
                <div class="border-b border-gray-100 px-4 py-3 space-y-2">
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">LLM Calls ({{ $runs->count() }})</p>
                    @foreach($runs as $run)
                        <div class="flex items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                            <i class="fa-solid fa-bolt fa-fw text-amber-400 shrink-0"></i>
                            <span class="text-xs font-mono text-gray-600 flex-1 truncate">{{ $run->model }}</span>
                            <span class="text-xs text-gray-400">{{ number_format($run->prompt_tokens + $run->completion_tokens) }} tok</span>
                            @if($run->duration_ms)
                                <span class="text-xs text-gray-400">{{ round($run->duration_ms / 1000, 1) }}s</span>
                            @endif
                            @if($run->cost_credits > 0)
                                <span class="text-xs text-gray-400">{{ number_format($run->cost_credits, 2) }}¢</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Output snapshot --}}
            @if(!empty($stage->output_snapshot))
                <details class="group">
                    <summary class="flex cursor-pointer items-center gap-2 px-4 py-2.5 text-xs font-medium text-gray-500 hover:bg-gray-50 rounded-b-xl">
                        <i class="fa-solid fa-arrow-right-from-bracket fa-fw text-green-400"></i>
                        Output
                        <i class="fa-solid fa-chevron-down ml-auto text-gray-300 transition-transform group-open:rotate-180"></i>
                    </summary>
                    <div class="px-4 pb-3">
                        @php
                            $outputText = $stage->output_snapshot['output'] ?? $stage->output_snapshot['result'] ?? $stage->output_snapshot['content'] ?? null;
                        @endphp
                        @if($outputText && is_string($outputText))
                            <div class="prose prose-sm max-w-none rounded-lg bg-gray-50 p-3 text-sm text-gray-700">
                                {!! nl2br(e($outputText)) !!}
                            </div>
                        @else
                            <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100">{{ json_encode($stage->output_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        @endif
                    </div>
                </details>
            @endif
        </div>
    @endforeach
</div>
