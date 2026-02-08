<div class="space-y-3">
    @forelse($stages as $stage)
        <div class="rounded-lg border border-gray-200 bg-white">
            <button wire:click="toggleStage('{{ $stage->id }}')"
                class="flex w-full items-center justify-between px-4 py-3 text-left transition hover:bg-gray-50">
                <div class="flex items-center gap-3">
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
                <div class="border-t border-gray-200 bg-gray-50 px-4 py-3">
                    <pre class="max-h-96 overflow-auto rounded bg-gray-900 p-3 text-xs text-green-400">{{ json_encode($stage->output_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-400">No stages yet. Start the experiment to begin the pipeline.</p>
        </div>
    @endforelse
</div>
