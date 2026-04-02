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
                    <i class="fa-solid fa-chevron-down text-base text-gray-400 transition {{ $expandedStageId === $stage->id ? 'rotate-180' : '' }}"></i>
                </div>
            </button>

            {{-- Routing metadata badges (shown inline on collapsed stage) --}}
            @php
                $latestRun = ($stageRuns[$stage->id] ?? collect())->sortByDesc('created_at')->first();
                $verificationErrors = is_array($stage->output_snapshot) ? ($stage->output_snapshot['_verification_errors'] ?? null) : null;
            @endphp
            @if($latestRun && ($latestRun->classified_complexity || $latestRun->budget_pressure_level || $latestRun->escalation_attempts > 0 || $latestRun->verification_passed !== null))
                <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-4 py-2 text-xs">
                    @if($latestRun->classified_complexity)
                        @php
                            $complexityColor = match($latestRun->classified_complexity) {
                                'light' => 'bg-green-100 text-green-700',
                                'heavy' => 'bg-purple-100 text-purple-700',
                                default => 'bg-blue-100 text-blue-700',
                            };
                        @endphp
                        <span class="rounded px-1.5 py-0.5 {{ $complexityColor }}">{{ $latestRun->classified_complexity }}</span>
                    @endif
                    @if($latestRun->budget_pressure_level)
                        @php
                            $pressureColor = match($latestRun->budget_pressure_level) {
                                'high' => 'bg-red-100 text-red-700',
                                'medium' => 'bg-orange-100 text-orange-700',
                                'low' => 'bg-yellow-100 text-yellow-700',
                                default => 'bg-gray-100 text-gray-500',
                            };
                        @endphp
                        <span class="rounded px-1.5 py-0.5 {{ $pressureColor }}">{{ $latestRun->budget_pressure_level }} pressure</span>
                    @endif
                    @if($latestRun->escalation_attempts > 0)
                        <span class="text-amber-600">&uarr; Escalated {{ $latestRun->escalation_attempts }}x</span>
                    @endif
                    @if($latestRun->verification_passed !== null)
                        @if($latestRun->verification_passed)
                            <span class="text-green-600">&#10003; Verified</span>
                        @else
                            <span class="text-red-600">&#10007; Verification failed</span>
                        @endif
                    @endif
                </div>
            @endif

            {{-- Verification errors from output_snapshot --}}
            @if($verificationErrors)
                <div class="flex items-center gap-1.5 border-t border-yellow-100 bg-yellow-50 px-4 py-1.5 text-xs text-yellow-700">
                    <i class="fa-solid fa-triangle-exclamation text-xs shrink-0"></i>
                    <span>Verification failed ({{ is_array($verificationErrors) ? count($verificationErrors) : 1 }} {{ is_array($verificationErrors) && count($verificationErrors) !== 1 ? 'errors' : 'error' }})</span>
                </div>
            @endif

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

    {{-- Stuck pattern events --}}
    @if($stuckTransitions->isNotEmpty())
        @php
            $stuckWithPattern = $stuckTransitions->filter(fn ($t) => !empty($t->metadata['stuck_pattern']));
        @endphp
        @if($stuckWithPattern->isNotEmpty())
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-amber-600">Stuck Patterns Detected</p>
                <div class="mt-2 space-y-1.5">
                    @foreach($stuckWithPattern as $transition)
                        <div class="flex items-center gap-2 text-xs text-amber-700">
                            <i class="fa-solid fa-triangle-exclamation text-xs shrink-0"></i>
                            <span>
                                {{ $transition->metadata['stuck_pattern'] }}
                                @if($transition->reason)
                                    &mdash; {{ $transition->reason }}
                                @endif
                                <span class="text-amber-500">({{ $transition->created_at->diffForHumans() }})</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
