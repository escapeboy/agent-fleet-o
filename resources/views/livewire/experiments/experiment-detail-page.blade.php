<div wire:poll.5s>
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <x-status-badge :status="$experiment->status->value" />
                <span class="text-sm text-gray-500">{{ ucfirst($experiment->track->value) }}</span>
                <span class="text-sm text-gray-500">Iteration {{ $experiment->current_iteration }}/{{ $experiment->max_iterations }}</span>
            </div>
            @if($experiment->thesis)
                <p class="mt-2 max-w-2xl text-sm text-gray-600">{{ $experiment->thesis }}</p>
            @endif
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-2">
            @if($experiment->status === \App\Domain\Experiment\Enums\ExperimentStatus::Draft)
                <button wire:click="startExperiment" wire:confirm="Start this experiment? It will begin the scoring stage."
                    class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Start Experiment
                </button>
            @endif

            @if($experiment->status->isPausable())
                <button wire:click="pauseExperiment"
                    class="rounded-lg border border-yellow-300 bg-yellow-50 px-3 py-2 text-sm font-medium text-yellow-700 hover:bg-yellow-100">
                    Pause
                </button>
            @endif

            @if($experiment->status === \App\Domain\Experiment\Enums\ExperimentStatus::Paused)
                <button wire:click="resumeExperiment"
                    class="rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-100">
                    Resume
                </button>
            @endif

            @if(!$experiment->status->isTerminal())
                @if($showKillConfirm)
                    <div class="flex items-center gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2">
                        <span class="text-sm text-red-700">Kill this experiment?</span>
                        <button wire:click="killExperiment" class="rounded bg-red-600 px-2 py-1 text-xs font-medium text-white hover:bg-red-700">Yes, kill</button>
                        <button wire:click="$set('showKillConfirm', false)" class="rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">Cancel</button>
                    </div>
                @else
                    <button wire:click="$set('showKillConfirm', true)"
                        class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                        Kill
                    </button>
                @endif
            @endif
        </div>
    </div>

    {{-- Stats Row --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-gray-500">Budget</p>
            @if($experiment->budget_cap_credits > 0)
                @php $pct = min(100, round(($experiment->budget_spent_credits / $experiment->budget_cap_credits) * 100)); @endphp
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ number_format($experiment->budget_spent_credits) }} / {{ number_format($experiment->budget_cap_credits) }}</p>
                <div class="mt-1 h-1.5 w-full rounded-full bg-gray-200">
                    <div class="h-1.5 rounded-full {{ $pct > 80 ? 'bg-red-500' : ($pct > 50 ? 'bg-yellow-500' : 'bg-green-500') }}" style="width: {{ $pct }}%"></div>
                </div>
            @else
                <p class="mt-1 text-lg font-semibold text-gray-900">No cap</p>
            @endif
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-gray-500">Stages</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $experiment->stages_count }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-gray-500">Outbound</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $experiment->outbound_count }}/{{ $experiment->max_outbound_count }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium text-gray-500">Metrics</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $experiment->metrics_count }}</p>
        </div>
    </div>

    {{-- Tab Navigation --}}
    <div class="mb-4 border-b border-gray-200">
        <nav class="-mb-px flex gap-6">
            @foreach(['timeline' => 'Timeline', 'artifacts' => 'Artifacts', 'outbound' => 'Outbound', 'metrics' => 'Metrics', 'transitions' => 'Transitions'] as $tab => $label)
                <button wire:click="$set('activeTab', '{{ $tab }}')"
                    class="border-b-2 px-1 pb-3 text-sm font-medium transition
                    {{ $activeTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                    @if($tab === 'artifacts')
                        <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $experiment->artifacts_count }}</span>
                    @elseif($tab === 'outbound')
                        <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $experiment->outbound_proposals_count }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'timeline')
        <livewire:experiments.experiment-timeline :experiment="$experiment" :key="'timeline-'.$experiment->id" />
    @elseif($activeTab === 'artifacts')
        <livewire:experiments.artifact-list :experiment="$experiment" :key="'artifacts-'.$experiment->id" />
    @elseif($activeTab === 'outbound')
        <livewire:experiments.outbound-log :experiment="$experiment" :key="'outbound-'.$experiment->id" />
    @elseif($activeTab === 'metrics')
        <livewire:experiments.metrics-panel :experiment="$experiment" :key="'metrics-'.$experiment->id" />
    @elseif($activeTab === 'transitions')
        <livewire:experiments.transitions-log :experiment="$experiment" :key="'transitions-'.$experiment->id" />
    @endif
</div>
