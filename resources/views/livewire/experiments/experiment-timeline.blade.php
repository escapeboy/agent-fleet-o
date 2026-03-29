<div class="space-y-3">
    {{-- Context health indicator — shown when any LLM calls have been made --}}
    @if($contextHealth && $contextHealth->totalInputTokens > 0)
        @php
            $healthLevel = $contextHealth->level();
            $healthColor = match($healthLevel) {
                'critical' => 'bg-red-100 text-red-700 border-red-200',
                'warning'  => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                default    => 'bg-green-100 text-green-700 border-green-200',
            };
            $healthIcon = match($healthLevel) {
                'critical' => '🔴',
                'warning'  => '🟡',
                default    => '🟢',
            };
        @endphp
        <div class="rounded-lg border px-4 py-2 text-xs font-medium {{ $healthColor }} flex items-center justify-between">
            <span>{{ $healthIcon }} Context window: {{ $contextHealth->contextUsedPercent() }}% used</span>
            <span class="text-gray-500">{{ number_format($contextHealth->totalInputTokens) }} / {{ number_format($contextHealth->contextWindowTokens) }} tokens · {{ $contextHealth->primaryModel }}</span>
        </div>
    @endif

    @forelse($stages as $stage)
        <div class="rounded-lg border border-gray-200 bg-white">
            <button wire:click="toggleStage('{{ $stage->id }}')"
                class="flex w-full items-center justify-between px-4 py-3 text-left transition hover:bg-gray-50">
                <div class="flex items-center gap-3">
                    <x-agent-status-indicator :status="$stage->status->value" size="sm" />
                    <x-status-badge :status="$stage->status->value" />
                    <span class="text-sm font-medium text-gray-900">{{ str_replace('_', ' ', ucfirst($stage->stage->value)) }}</span>
                    <span class="text-xs text-gray-400">Iteration {{ $stage->iteration }}</span>
                </div>
                <div class="flex items-center gap-3">
                    @if($stage->duration_ms)
                        <span class="text-xs text-gray-400">{{ number_format($stage->duration_ms) }}ms</span>
                    @endif
                    <span class="text-xs text-gray-400">{{ $stage->created_at->diffForHumans() }}</span>
                    <svg class="h-4 w-4 text-gray-400 transition {{ $expandedStageId === $stage->id ? 'rotate-180' : '' }}"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </button>

            @if($expandedStageId === $stage->id && $stage->output_snapshot)
                <div x-data="{ showRaw: false }" class="border-t border-gray-200 bg-gray-50 px-4 py-3">
                    <div class="mb-2 flex items-center justify-end">
                        <button @click="showRaw = !showRaw" class="text-xs text-primary-600 hover:underline">
                            <span x-text="showRaw ? 'Formatted' : 'Raw JSON'"></span>
                        </button>
                    </div>

                    {{-- Formatted markdown view --}}
                    <div x-show="!showRaw" class="prose prose-sm max-h-96 overflow-auto">
                        @php
                            $stageText = is_array($stage->output_snapshot)
                                ? ($stage->output_snapshot['plan_summary'] ?? $stage->output_snapshot['result'] ?? json_encode($stage->output_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                                : (string) $stage->output_snapshot;
                        @endphp
                        {!! \Illuminate\Support\Str::markdown($stageText) !!}
                    </div>

                    {{-- Raw JSON view --}}
                    <pre x-show="showRaw" x-cloak class="max-h-96 overflow-auto rounded bg-gray-900 p-3 text-xs text-green-400">{{ json_encode($stage->output_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-400">No stages yet. Start the experiment to begin the pipeline.</p>
        </div>
    @endforelse
</div>
