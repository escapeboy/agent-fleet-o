<div wire:poll.10s>
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <x-status-badge :status="$experiment->status->value" />
                <span class="text-sm text-gray-500">{{ ucfirst($experiment->track->value) }}</span>
                <span class="text-sm text-gray-500">Iteration {{ $experiment->current_iteration }}/{{ $experiment->max_iterations }}</span>
                @if($experiment->constraints['auto_approve'] ?? false)
                    <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Auto-approve</span>
                @endif
            </div>
            @if($experiment->thesis)
                <p class="mt-2 max-w-2xl text-sm text-gray-600">{{ $experiment->thesis }}</p>
            @endif
            @if(!empty($experiment->success_criteria))
                <div class="mt-2 max-w-2xl">
                    <p class="text-xs font-medium text-gray-500">Success Criteria</p>
                    <ul class="mt-1 list-inside list-disc text-sm text-gray-600">
                        @foreach($experiment->success_criteria as $criterion)
                            <li>{{ $criterion }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-2">
            @if($experiment->status === \App\Domain\Experiment\Enums\ExperimentStatus::Draft)
                <button wire:click="startExperiment" wire:confirm="Start this run? It will begin the scoring stage."
                    class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Start Run
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

            @if($experiment->status->isFailed())
                @if($showRetryConfirm)
                    <div class="flex items-center gap-2 rounded-lg border border-blue-300 bg-blue-50 px-3 py-2">
                        <span class="text-sm text-blue-700">Retry this run?</span>
                        <button wire:click="retryExperiment" class="rounded bg-blue-600 px-2 py-1 text-xs font-medium text-white hover:bg-blue-700">Yes, retry</button>
                        <button wire:click="$set('showRetryConfirm', false)" class="rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">Cancel</button>
                    </div>
                @else
                    <button wire:click="$set('showRetryConfirm', true)"
                        class="rounded-lg border border-blue-300 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
                        Retry
                    </button>
                @endif
            @endif

            @if(!$experiment->status->isTerminal())
                @if($showKillConfirm)
                    <div class="flex items-center gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2">
                        <span class="text-sm text-red-700">Kill this run?</span>
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
            @if($experiment->hasWorkflow())
                @php
                    $totalSteps = $experiment->playbook_steps_count;
                    $completedSteps = $experiment->playbookSteps()->where('status', 'completed')->count();
                    $stepPct = $totalSteps > 0 ? min(100, round(($completedSteps / $totalSteps) * 100)) : 0;
                @endphp
                <p class="text-xs font-medium text-gray-500">Steps</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $completedSteps }}/{{ $totalSteps }} completed</p>
                <div class="mt-1 h-1.5 w-full rounded-full bg-gray-200">
                    <div class="h-1.5 rounded-full bg-green-500" style="width: {{ $stepPct }}%"></div>
                </div>
            @else
                <p class="text-xs font-medium text-gray-500">Stages</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $experiment->stages_count }}</p>
            @endif
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

    {{-- Workflow Progress (shown when experiment uses a workflow) --}}
    @if($experiment->hasWorkflow())
        <div class="mb-6">
            <livewire:experiments.workflow-progress-panel :experimentId="$experiment->id" :key="'workflow-progress-'.$experiment->id" />
        </div>
    @endif

    {{-- Tab Navigation --}}
    <div class="mb-4 border-b border-gray-200">
        <nav class="-mb-px flex gap-6">
            @php
                $tabs = $experiment->hasWorkflow()
                    ? ['tasks' => 'Tasks', 'artifacts' => 'Artifacts', 'outbound' => 'Outbound', 'metrics' => 'Metrics', 'execution-log' => 'Execution Log', 'transitions' => 'Transitions']
                    : ['timeline' => 'Timeline', 'tasks' => 'Tasks', 'artifacts' => 'Artifacts', 'outbound' => 'Outbound', 'metrics' => 'Metrics', 'execution-log' => 'Execution Log', 'transitions' => 'Transitions'];
            @endphp
            @foreach($tabs as $tab => $label)
                <button wire:click="$set('activeTab', '{{ $tab }}')"
                    class="border-b-2 px-1 pb-3 text-sm font-medium transition
                    {{ $activeTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                    @if($tab === 'tasks' && $experiment->tasks_count > 0)
                        <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $experiment->tasks_count }}</span>
                    @elseif($tab === 'artifacts')
                        <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $experiment->artifacts_count }}</span>
                    @elseif($tab === 'outbound')
                        <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $experiment->outbound_proposals_count }}</span>
                    @elseif($tab === 'metrics' && $experiment->metrics_count > 0)
                        <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $experiment->metrics_count }}</span>
                    @elseif($tab === 'transitions' && $experiment->state_transitions_count > 0)
                        <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $experiment->state_transitions_count }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'timeline')
        <livewire:experiments.experiment-timeline :experiment="$experiment" :key="'timeline-'.$experiment->id" />
    @elseif($activeTab === 'tasks')
        <livewire:experiments.experiment-tasks-panel :experiment="$experiment" :key="'tasks-'.$experiment->id" />
    @elseif($activeTab === 'artifacts')
        <livewire:experiments.artifact-list :experiment="$experiment" :key="'artifacts-'.$experiment->id" />
    @elseif($activeTab === 'outbound')
        <livewire:experiments.outbound-log :experiment="$experiment" :key="'outbound-'.$experiment->id" />
    @elseif($activeTab === 'metrics')
        <livewire:experiments.metrics-panel :experiment="$experiment" :key="'metrics-'.$experiment->id" />
    @elseif($activeTab === 'execution-log')
        <livewire:experiments.execution-log-panel :experimentId="$experiment->id" :key="'execution-log-'.$experiment->id" />
    @elseif($activeTab === 'transitions')
        <livewire:experiments.transitions-log :experiment="$experiment" :key="'transitions-'.$experiment->id" />
    @endif
</div>
