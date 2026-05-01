<div class="space-y-3">
    @if($runs->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center text-sm text-gray-500">
            No LLM calls recorded for this experiment.
        </div>
    @endif

    @foreach($runs as $index => $run)
        @php
            $isExpanded = in_array($run->id, $expandedBlocks);
            $totalTokens = ($run->input_tokens ?? 0) + ($run->output_tokens ?? 0);
            $durationSec = $run->duration_ms ? round($run->duration_ms / 1000, 2) : null;
            $exitStatus = $run->schema_valid === false ? 'schema_error' : ($run->has_reasoning ? 'reasoning' : 'ok');
            $statusColor = match($exitStatus) {
                'schema_error' => 'bg-red-100 text-red-700 border-red-200',
                'reasoning' => 'bg-purple-100 text-purple-700 border-purple-200',
                default => 'bg-green-100 text-green-700 border-green-200',
            };
        @endphp

        {{-- Block --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm" id="airun-{{ $run->id }}">

            {{-- Block header --}}
            <button
                wire:click="toggleBlock('{{ $run->id }}')"
                class="w-full flex items-center justify-between px-4 py-3 rounded-xl hover:bg-gray-50 transition text-left"
                :aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
            >
                <div class="flex items-center gap-3 min-w-0">
                    {{-- Block number --}}
                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600">
                        {{ $index + 1 }}
                    </span>

                    {{-- Purpose / model --}}
                    <div class="min-w-0">
                        <span class="text-sm font-medium text-gray-800 capitalize truncate block">
                            {{ str_replace('_', ' ', $run->purpose ?? 'llm call') }}
                        </span>
                        <span class="text-xs text-gray-400 font-mono">{{ $run->provider }}/{{ $run->model }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-3 shrink-0 ml-4">
                    {{-- Exit status --}}
                    <span class="rounded-full border px-2 py-0.5 text-xs font-medium {{ $statusColor }}">
                        @if($exitStatus === 'schema_error') schema error
                        @elseif($exitStatus === 'reasoning') reasoning
                        @else ok
                        @endif
                    </span>

                    {{-- Stats --}}
                    @if($totalTokens > 0)
                        <span class="text-xs text-gray-400 tabular-nums">{{ number_format($totalTokens) }} tok</span>
                    @endif
                    @if($durationSec)
                        <span class="text-xs text-gray-400 tabular-nums">{{ $durationSec }}s</span>
                    @endif
                    @if($run->cost_credits)
                        <span class="text-xs text-gray-400 tabular-nums">{{ number_format($run->cost_credits, 2) }}¢</span>
                    @endif
                    <span class="text-xs text-gray-400">{{ $run->created_at->format('H:i:s') }}</span>

                    {{-- Expand chevron --}}
                    <i class="fa-solid fa-chevron-down text-gray-300 text-xs transition-transform {{ $isExpanded ? 'rotate-180' : '' }}"></i>
                </div>
            </button>

            {{-- Expanded block body --}}
            @if($isExpanded)
                <div class="border-t border-gray-100 divide-y divide-gray-100">

                    {{-- Token breakdown --}}
                    @if($run->input_tokens || $run->output_tokens)
                        <div class="flex items-center gap-6 px-4 py-2.5 text-xs text-gray-500">
                            <span><span class="font-medium">In:</span> {{ number_format($run->input_tokens ?? 0) }}</span>
                            <span><span class="font-medium">Out:</span> {{ number_format($run->output_tokens ?? 0) }}</span>
                            @if($run->cost_credits)
                                <span><span class="font-medium">Cost:</span> {{ number_format($run->cost_credits, 4) }} credits</span>
                            @endif
                        </div>
                    @endif

                    {{-- Reasoning output --}}
                    @if($run->has_reasoning && !empty($run->raw_output['thinking']))
                        <details class="group">
                            <summary class="flex cursor-pointer items-center gap-2 px-4 py-2.5 text-xs font-medium text-purple-600 hover:bg-purple-50">
                                <i class="fa-solid fa-brain fa-fw"></i>
                                Reasoning
                                <i class="fa-solid fa-chevron-down ml-auto text-purple-300 transition-transform group-open:rotate-180"></i>
                            </summary>
                            <div class="px-4 pb-3">
                                <pre class="overflow-x-auto rounded-lg bg-purple-50 p-3 text-xs text-purple-900 whitespace-pre-wrap">{{ $run->raw_output['thinking'] }}</pre>
                            </div>
                        </details>
                    @endif

                    {{-- Raw output --}}
                    @if(!empty($run->raw_output))
                        <details class="group">
                            <summary class="flex cursor-pointer items-center gap-2 px-4 py-2.5 text-xs font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fa-solid fa-arrow-right-from-bracket fa-fw text-green-400"></i>
                                Output
                                <i class="fa-solid fa-chevron-down ml-auto text-gray-300 transition-transform group-open:rotate-180"></i>
                            </summary>
                            <div class="px-4 pb-3">
                                @php
                                    $textContent = $run->raw_output['content'] ?? $run->raw_output['text'] ?? null;
                                @endphp
                                @if($textContent && is_string($textContent))
                                    <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-800 whitespace-pre-wrap max-h-64 overflow-y-auto">{{ $textContent }}</div>
                                @else
                                    <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100 max-h-64 overflow-y-auto">{{ json_encode($run->raw_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                @endif
                            </div>
                        </details>
                    @endif

                    {{-- Permalink --}}
                    <div class="px-4 py-2 flex items-center gap-2">
                        <a href="#airun-{{ $run->id }}" class="text-xs text-gray-400 hover:text-gray-600 font-mono">
                            <i class="fa-solid fa-link mr-1"></i>{{ substr($run->id, 0, 8) }}…
                        </a>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
