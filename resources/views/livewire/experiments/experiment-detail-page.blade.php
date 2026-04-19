<div wire:poll.10s x-data="wakeLock" x-init="{{ $experiment->status->isPausable() ? 'acquire()' : '' }}">
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <x-agent-status-indicator :status="$experiment->status->value" size="sm" />
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
        <div class="flex flex-wrap items-center gap-2">
            @if($experiment->status === \App\Domain\Experiment\Enums\ExperimentStatus::Draft)
                <form class="inline" onsubmit="return false" toolname="start_experiment" tooldescription="Start this experiment — transition from draft to scoring">
                <button type="button" wire:click="startExperiment" wire:confirm="Start this run? It will begin the scoring stage."
                    class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Start Run
                </button>
                </form>
            @endif

            @if($experiment->status->isPausable())
                <form class="inline" onsubmit="return false" toolname="pause_experiment" tooldescription="Pause this running experiment">
                <button type="button" wire:click="pauseExperiment"
                    class="rounded-lg border border-yellow-300 bg-yellow-50 px-3 py-2 text-sm font-medium text-yellow-700 hover:bg-yellow-100">
                    Pause
                </button>
                </form>

                <form class="inline" onsubmit="return false" toolname="steer_experiment" tooldescription="Inject a mid-run steering message into the next LLM call">
                <button type="button" wire:click="openSteerModal"
                    title="Inject a one-shot correction into the next LLM call"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">
                    Steer
                    @if(!empty($experiment->orchestration_config['steering_message'] ?? null))
                        <span class="inline-flex items-center rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                            title="A steering message is queued — will inject on the next LLM call">
                            queued
                        </span>
                    @endif
                </button>
                </form>
            @endif

            @if($experiment->status === \App\Domain\Experiment\Enums\ExperimentStatus::Paused)
                <form class="inline" onsubmit="return false" toolname="resume_experiment" tooldescription="Resume this paused experiment">
                <button type="button" wire:click="resumeExperiment"
                    class="rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-100">
                    Resume
                </button>
                </form>
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

            @if($experiment->status->isFailed() || $experiment->status === \App\Domain\Experiment\Enums\ExperimentStatus::Paused)
                @if($showResumeCheckpointConfirm)
                    <div class="flex items-center gap-2 rounded-lg border border-purple-300 bg-purple-50 px-3 py-2">
                        <span class="text-sm text-purple-700">Resume from last checkpoint?</span>
                        <button wire:click="resumeFromCheckpoint" class="rounded bg-purple-600 px-2 py-1 text-xs font-medium text-white hover:bg-purple-700">Yes, resume</button>
                        <button wire:click="$set('showResumeCheckpointConfirm', false)" class="rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">Cancel</button>
                    </div>
                @else
                    <button wire:click="$set('showResumeCheckpointConfirm', true)"
                        class="rounded-lg border border-purple-300 bg-purple-50 px-3 py-2 text-sm font-medium text-purple-700 hover:bg-purple-100">
                        Resume Checkpoint
                    </button>
                @endif
            @endif

            @if(!$experiment->status->isTerminal())
                @if($showKillConfirm)
                    <div class="flex items-center gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2">
                        <span class="text-sm text-red-700">Kill this run?</span>
                        <form class="inline" onsubmit="return false" toolname="kill_experiment" tooldescription="Permanently terminate this experiment">
                        <button type="button" wire:click="killExperiment" class="rounded bg-red-600 px-2 py-1 text-xs font-medium text-white hover:bg-red-700">Yes, kill</button>
                        </form>
                        <button wire:click="$set('showKillConfirm', false)" class="rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">Cancel</button>
                    </div>
                @else
                    <button wire:click="$set('showKillConfirm', true)"
                        class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                        Kill
                    </button>
                @endif
            @endif

            {{-- Native share / Copy link --}}
            <div x-data="webShare">
                <button @click="share('{{ $experiment->name }}', '{{ $experiment->thesis }}', '{{ url()->current() }}')"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                    :title="copied ? 'Link copied!' : 'Share'">
                    <span x-text="copied ? 'Copied!' : (canShare ? 'Share' : 'Copy link')"></span>
                </button>
            </div>

            {{-- Share modal (stakeholder public link) --}}
            <button wire:click="openShareModal"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                {{ $experiment->share_enabled ? '🔗 Sharing On' : 'Public Link' }}
            </button>
            <x-send-to-assistant-button
                :message="'Debug experiment: ' . $experiment->name . '. Status: ' . $experiment->status->value . ($experiment->thesis ? '. Thesis: ' . $experiment->thesis : '')"
            />
        </div>
    </div>

    {{-- 3-Phase Pipeline Stepper --}}
    @php
        $phase = $this->getPipelinePhase();
        // Determine if the current phase 2 status is a soft-failure (amber tint).
        $isPhase2Failure = $phase === 2 && in_array($experiment->status, [
            \App\Domain\Experiment\Enums\ExperimentStatus::ScoringFailed,
            \App\Domain\Experiment\Enums\ExperimentStatus::PlanningFailed,
            \App\Domain\Experiment\Enums\ExperimentStatus::BuildingFailed,
            \App\Domain\Experiment\Enums\ExperimentStatus::ExecutionFailed,
            \App\Domain\Experiment\Enums\ExperimentStatus::Rejected,
            \App\Domain\Experiment\Enums\ExperimentStatus::Expired,
        ]);
        // Terminal failure: killed or discarded (phase 3 shows as gray, not green).
        $isTerminalFailure = in_array($experiment->status, [
            \App\Domain\Experiment\Enums\ExperimentStatus::Killed,
            \App\Domain\Experiment\Enums\ExperimentStatus::Discarded,
        ]);
        $steps = [
            1 => 'Define Goal',
            2 => 'Execute Plan',
            3 => 'Review Results',
        ];
    @endphp
    <div class="mb-6 flex items-center" aria-label="Experiment pipeline phases">
        @foreach($steps as $step => $label)
            @php
                $isCompleted = $step < $phase || ($step === 3 && $phase === 3 && !$isTerminalFailure);
                $isActive    = $step === $phase;
                $isFuture    = $step > $phase;
                // Phase 3 terminal failure: active but shown as gray.
                $isGrayTerminal = $step === 3 && $phase === 3 && $isTerminalFailure;
            @endphp

            {{-- Step circle + label --}}
            <div class="flex flex-col items-center">
                @if($isCompleted)
                    {{-- Completed: filled indigo circle with checkmark --}}
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600">
                        <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>
                @elseif($isActive && $isPhase2Failure)
                    {{-- Active phase 2 with soft failure: amber tint + pulse --}}
                    <div class="relative flex h-8 w-8 items-center justify-center">
                        <span class="absolute inline-flex h-full w-full animate-pulse rounded-full bg-amber-300 opacity-50"></span>
                        <div class="relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-amber-500 bg-amber-50">
                            <span class="text-xs font-bold text-amber-700">{{ $step }}</span>
                        </div>
                    </div>
                @elseif($isActive && !$isGrayTerminal)
                    {{-- Active phase: pulsing indigo ring --}}
                    <div class="relative flex h-8 w-8 items-center justify-center">
                        <span class="absolute inline-flex h-full w-full animate-pulse rounded-full bg-indigo-400 opacity-40"></span>
                        <div class="relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-indigo-600 bg-indigo-50">
                            <span class="text-xs font-bold text-indigo-700">{{ $step }}</span>
                        </div>
                    </div>
                @elseif($isGrayTerminal)
                    {{-- Phase 3 terminal failure: gray circle (ended, not succeeded) --}}
                    <div class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-gray-400 bg-gray-100">
                        <span class="text-xs font-medium text-gray-500">{{ $step }}</span>
                    </div>
                @else
                    {{-- Future phase: gray empty circle --}}
                    <div class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-gray-300 bg-white">
                        <span class="text-xs font-medium text-gray-400">{{ $step }}</span>
                    </div>
                @endif

                {{-- Label below circle --}}
                <span class="mt-1.5 whitespace-nowrap text-xs
                    @if($isActive && !$isGrayTerminal && !$isPhase2Failure) font-semibold text-indigo-700
                    @elseif($isActive && $isPhase2Failure) font-semibold text-amber-700
                    @elseif($isGrayTerminal) font-medium text-gray-500
                    @elseif($isCompleted) font-medium text-indigo-600
                    @else text-gray-400
                    @endif">
                    {{ $label }}
                </span>
            </div>

            {{-- Connector line between steps (not after last step) --}}
            @if($step < count($steps))
                @php
                    // Line is solid indigo when the segment is fully completed (next step started).
                    $lineCompleted = $step < $phase;
                @endphp
                <div class="mx-2 mb-5 h-0.5 w-16 flex-shrink-0
                    @if($lineCompleted) bg-indigo-500
                    @else border-t-2 border-dashed border-gray-300 bg-transparent
                    @endif">
                </div>
            @endif
        @endforeach
    </div>

    {{-- Steer Modal --}}
    @if($showSteerModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
            role="dialog" aria-modal="true" aria-labelledby="steer-modal-title"
            @keydown.escape.window="$wire.closeSteerModal()">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl"
                @click.away="$wire.closeSteerModal()">
                <div class="mb-4 flex items-center justify-between">
                    <h3 id="steer-modal-title" class="text-lg font-semibold text-gray-900">Steer Experiment</h3>
                    <button wire:click="closeSteerModal" class="text-gray-400 hover:text-gray-600" aria-label="Close">✕</button>
                </div>

                <p class="mb-3 text-sm text-gray-600">
                    Inject a one-shot correction into the next LLM call for this experiment. The message is prepended to the system prompt and cleared after the first use.
                </p>

                @if($queuedSteeringMessage = $experiment->orchestration_config['steering_message'] ?? null)
                    <div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800">
                        <strong>Queued:</strong> a previous steering message is still in the queue. Submitting a new one will replace it.
                        <div class="mt-1 italic">"{{ \Illuminate\Support\Str::limit($queuedSteeringMessage, 140) }}"</div>
                    </div>
                @endif

                <label class="mb-1 block text-sm font-medium text-gray-700" for="steering-message">Steering message</label>
                <textarea id="steering-message" wire:model="steeringMessage" rows="4" maxlength="2000"
                    placeholder="e.g. Use the staging database, not production."
                    class="w-full rounded-lg border border-gray-300 p-2 text-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                <p class="mt-1 text-xs text-gray-500">Up to 2000 characters. Prepended to the system prompt once, then cleared.</p>
                @error('steeringMessage')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror

                <div class="mt-4 flex items-center justify-end gap-2">
                    <button wire:click="closeSteerModal"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="submitSteering"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Queue steering
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Share Modal --}}
    @if($showShareModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Share Experiment</h3>
                    <button wire:click="$set('showShareModal', false)" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                @if(!$experiment->share_token)
                    <p class="mb-4 text-sm text-gray-600">Generate a public link so stakeholders can view this experiment without signing in.</p>
                    <button wire:click="generateShareToken"
                        class="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Generate Share Link
                    </button>
                @else
                    @if(session('share_saved'))
                        <p class="mb-3 text-xs text-green-600">Settings saved.</p>
                    @endif

                    {{-- Share URL --}}
                    <div class="mb-4">
                        <label class="mb-1 block text-xs font-medium text-gray-700">Share URL</label>
                        <div class="flex gap-2">
                            <input type="text" readonly
                                value="{{ route('experiments.share', $experiment->share_token) }}"
                                class="flex-1 rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-xs text-gray-600"
                                onclick="this.select(); document.execCommand('copy')" />
                            <button onclick="navigator.clipboard.writeText('{{ route('experiments.share', $experiment->share_token) }}')"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-xs text-gray-600 hover:bg-gray-50">
                                Copy
                            </button>
                        </div>
                    </div>

                    {{-- Visibility options --}}
                    <div class="mb-4 space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="shareShowStages" class="rounded">
                            Show pipeline stages
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="shareShowOutputs" class="rounded">
                            Show stage outputs
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="shareShowCosts" class="rounded">
                            Show cost data
                        </label>
                    </div>

                    {{-- Expiry --}}
                    <div class="mb-4">
                        <label class="mb-1 block text-xs font-medium text-gray-700">Expires at (optional)</label>
                        <input type="datetime-local" wire:model="shareExpiresAt"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                    </div>

                    <div class="flex gap-2">
                        <button wire:click="updateShareConfig"
                            class="flex-1 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Save Settings
                        </button>
                        <button wire:click="revokeShare" wire:confirm="Revoke the share link? Anyone with the link will lose access."
                            class="rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                            Revoke
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Parent experiment link --}}
    @if($experiment->parent_experiment_id)
        <div class="mb-4 flex items-center gap-2 rounded-lg border border-purple-200 bg-purple-50 px-3 py-2 text-sm">
            <span class="text-purple-600">Sub-experiment of</span>
            <a href="{{ route('experiments.show', $experiment->parent_experiment_id) }}" class="font-medium text-purple-700 hover:underline">
                {{ $experiment->parent?->title ?? 'Parent Experiment' }}
            </a>
            <span class="text-purple-400">Depth: {{ $experiment->nesting_depth }}</span>
        </div>
    @endif

    {{-- Orchestration tree (shown above stats when experiment is part of a tree) --}}
    @if($hasOrchestration)
        <div class="mb-6">
            <livewire:experiments.orchestration-tree :experiment="$experiment" :key="'orch-'.$experiment->id" />
        </div>
    @endif

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

    {{-- Tab Navigation --}}
    <div class="mb-4 border-b border-gray-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto scrollbar-none">
            @php
                $workflowTabs = ['tasks' => 'Tasks', 'artifacts' => 'Artifacts', 'time-travel' => 'Time Travel', 'outbound' => 'Outbound', 'metrics' => 'Metrics', 'cost' => 'Cost', 'chain' => 'Execution Chain', 'suggestions' => 'Suggestions', 'reasoning' => 'Reasoning', 'execution-log' => 'Execution Log', 'transitions' => 'Transitions', 'worklog' => 'Worklog'.($worklogCount > 0 ? " ({$worklogCount})" : ''), 'uncertainty' => 'Signals'.($uncertaintyCount > 0 ? " ({$uncertaintyCount})" : '')];
                $standardTabs = ['timeline' => 'Timeline', 'tasks' => 'Tasks', 'artifacts' => 'Artifacts', 'outbound' => 'Outbound', 'metrics' => 'Metrics', 'cost' => 'Cost', 'reasoning' => 'Reasoning', 'execution-log' => 'Execution Log', 'transitions' => 'Transitions', 'worklog' => 'Worklog'.($worklogCount > 0 ? " ({$worklogCount})" : ''), 'uncertainty' => 'Signals'.($uncertaintyCount > 0 ? " ({$uncertaintyCount})" : '')];
                if ($experiment->status->isFailed()) {
                    $workflowTabs['lessons'] = 'Lessons Learned';
                    $standardTabs['lessons'] = 'Lessons Learned';
                }
                $tabs = $experiment->hasWorkflow() ? $workflowTabs : $standardTabs;
            @endphp
            @foreach($tabs as $tab => $label)
                <button wire:click="$set('activeTab', '{{ $tab }}')"
                    class="whitespace-nowrap border-b-2 px-1 pb-3 text-sm font-medium transition
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
                    @elseif($tab === 'chain')
                        @php $chainCount = \App\Domain\Workflow\Models\WorkflowNodeEvent::where('experiment_id', $experiment->id)->count(); @endphp
                        @if($chainCount > 0)
                            <span class="ml-1 rounded-full bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-700">{{ $chainCount }}</span>
                        @endif
                    @elseif($tab === 'reasoning' && $reasoningCount > 0)
                        <span class="ml-1 rounded-full bg-purple-100 px-1.5 py-0.5 text-xs text-purple-700">{{ $reasoningCount }}</span>
                    @elseif($tab === 'lessons' && $failureLessons->isNotEmpty())
                        <span class="ml-1 rounded-full bg-red-100 px-1.5 py-0.5 text-xs text-red-700">{{ $failureLessons->count() }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'timeline')
        <livewire:experiments.experiment-timeline :experiment="$experiment" :key="'timeline-'.$experiment->id" />
    @elseif($activeTab === 'time-travel')
        <livewire:experiments.workflow-timeline :experimentId="$experiment->id" :key="'time-travel-'.$experiment->id" />
    @elseif($activeTab === 'tasks')
        <livewire:experiments.experiment-tasks-panel :experiment="$experiment" :key="'tasks-'.$experiment->id" />
    @elseif($activeTab === 'artifacts')
        <livewire:experiments.artifact-list :artifact-owner="$experiment" :show-failed-tasks="true" :key="'artifacts-'.$experiment->id" />
    @elseif($activeTab === 'outbound')
        <livewire:experiments.outbound-log :experiment="$experiment" :key="'outbound-'.$experiment->id" />
    @elseif($activeTab === 'metrics')
        <livewire:experiments.metrics-panel :experiment="$experiment" :key="'metrics-'.$experiment->id" />
    @elseif($activeTab === 'cost')
        <livewire:experiments.cost-breakdown-panel :experiment="$experiment" :key="'cost-'.$experiment->id" />
    @elseif($activeTab === 'execution-log')
        <livewire:experiments.execution-log-panel :experimentId="$experiment->id" :key="'execution-log-'.$experiment->id" />
    @elseif($activeTab === 'transitions')
        <livewire:experiments.transitions-log :experiment="$experiment" :key="'transitions-'.$experiment->id" />
    @elseif($activeTab === 'reasoning')
        <div class="space-y-3">
            <div class="mb-2">
                <h3 class="text-sm font-semibold text-gray-700">Decision Reasoning Trail</h3>
                <p class="mt-0.5 text-xs text-gray-500">Step-by-step thought process captured from AI runs with extended thinking or tool-use chains.</p>
            </div>

            @forelse($reasoningRuns as $run)
                <div class="rounded-xl border border-gray-200 bg-white" x-data="{ open: false }">
                    <button @click="open = !open"
                        class="flex w-full items-center justify-between px-5 py-4 text-left">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-700">
                                {{ $run->provider }}/{{ $run->model }}
                            </span>
                            <span class="text-sm font-medium text-gray-800">{{ $run->purpose ?? 'AI Run' }}</span>
                            <span class="text-xs text-gray-400">{{ count($run->reasoning_chain ?? []) }} reasoning steps</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400">{{ $run->created_at->diffForHumans() }}</span>
                            <svg class="h-4 w-4 text-gray-400 transition" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                    </button>

                    <div x-show="open" x-collapse class="border-t border-gray-100 px-5 pb-5">
                        <ol class="mt-3 space-y-3">
                            @foreach($run->reasoning_chain ?? [] as $step)
                                <li class="flex gap-3">
                                    <div class="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-purple-100 text-xs font-bold text-purple-700">
                                        {{ $step['step'] ?? ($loop->index + 1) }}
                                    </div>
                                    <div class="flex-1">
                                        @if(!empty($step['thought']))
                                            <p class="text-sm text-gray-700">{{ $step['thought'] }}</p>
                                        @endif
                                        @if(!empty($step['action']) && $step['action'] !== 'thinking')
                                            <p class="mt-1 text-xs text-gray-500">
                                                <span class="font-medium text-gray-700">Action:</span> {{ $step['action'] }}
                                                @if(!empty($step['result']))
                                                    — <span class="text-gray-500">{{ is_array($step['result']) ? json_encode($step['result']) : $step['result'] }}</span>
                                                @endif
                                            </p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-10 text-center">
                    <svg class="mx-auto h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.346.346a3.001 3.001 0 01-4.243 0l-.346-.346a5 5 0 010-7.072z"/>
                    </svg>
                    <p class="mt-2 text-sm text-gray-500">No reasoning data captured yet.</p>
                    <p class="mt-1 text-xs text-gray-400">Reasoning chains are captured from Anthropic extended thinking and tool-augmented AI runs.</p>
                </div>
            @endforelse
        </div>

    @elseif($activeTab === 'chain')
        <livewire:experiments.execution-chain-panel :experiment="$experiment" :key="'chain-'.$experiment->id" />

    @elseif($activeTab === 'lessons')
        {{-- Lessons Learned: failure-tier memory records extracted from this experiment --}}
        <div class="space-y-4">
            <div class="mb-2">
                <h3 class="text-sm font-semibold text-gray-700">Lessons Learned</h3>
                <p class="mt-0.5 text-xs text-gray-500">Automatically extracted failure lessons stored in the team memory for future reference.</p>
            </div>

            @if($failureLessons->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-10 text-center">
                    <svg class="mx-auto h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="mt-2 text-sm text-gray-500">Extracting lesson…</p>
                    <p class="mt-1 text-xs text-gray-400">A background job analyses this failure and stores a lesson. Refresh in a few seconds.</p>
                </div>
            @else
                @foreach($failureLessons as $lesson)
                    <div class="rounded-xl border border-red-100 bg-red-50 p-5">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-red-100">
                                <svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm text-gray-800">{{ $lesson->content }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-gray-500">
                                    @if(!empty($lesson->metadata['root_cause']))
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 font-medium text-red-700">{{ $lesson->metadata['root_cause'] }}</span>
                                    @endif
                                    @if(!empty($lesson->metadata['final_status']))
                                        <span>status: {{ $lesson->metadata['final_status'] }}</span>
                                    @endif
                                    <span>extracted: {{ $lesson->created_at?->diffForHumans() }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

    @elseif($activeTab === 'worklog')
        <div class="space-y-3">
            <div class="mb-2">
                <h3 class="text-sm font-semibold text-gray-700">Experiment Worklog</h3>
                <p class="mt-0.5 text-xs text-gray-500">Structured per-step reasoning log written by agents during execution.</p>
            </div>
            @if($worklogs->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-10 text-center">
                    <p class="text-sm text-gray-500">No worklog entries yet.</p>
                </div>
            @else
                @foreach($worklogs as $entry)
                    @php
                        $pillClass = match($entry->type) {
                            'decision'    => 'bg-blue-100 text-blue-700',
                            'finding'     => 'bg-green-100 text-green-700',
                            'uncertainty' => 'bg-amber-100 text-amber-700',
                            'output'      => 'bg-purple-100 text-purple-700',
                            default       => 'bg-gray-100 text-gray-600',
                        };
                    @endphp
                    <div class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $pillClass }}">{{ $entry->type }}</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ $entry->content }}</p>
                                @if(!empty($entry->metadata))
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-600">Metadata</summary>
                                        <pre class="mt-1 overflow-x-auto rounded bg-gray-50 p-2 text-xs text-gray-600">{{ json_encode($entry->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                                <p class="mt-1.5 text-xs text-gray-400">{{ $entry->created_at?->diffForHumans() }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

    @elseif($activeTab === 'uncertainty')
        <div class="space-y-3">
            <div class="mb-2">
                <h3 class="text-sm font-semibold text-gray-700">Uncertainty Signals</h3>
                <p class="mt-0.5 text-xs text-gray-500">Open questions and uncertainties raised by agents during execution.</p>
            </div>
            @if($uncertaintySignals->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-10 text-center">
                    <p class="text-sm text-gray-500">No uncertainty signals recorded.</p>
                </div>
            @else
                @php
                    $pending  = $uncertaintySignals->where('status', 'pending');
                    $resolved = $uncertaintySignals->where('status', 'resolved');
                @endphp
                @foreach($pending as $signal)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-200 text-amber-700 text-xs font-bold">?</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-amber-900">{{ $signal->signal_text }}</p>
                                @if($signal->context)
                                    <p class="mt-1 text-xs text-amber-700">{{ is_array($signal->context) ? ($signal->context['description'] ?? '') : $signal->context }}</p>
                                @endif
                                @if($signal->ttl_minutes)
                                    <p class="mt-1 text-xs text-amber-600">TTL: {{ $signal->ttl_minutes }} min · {{ $signal->isExpired() ? 'Expired' : 'Active' }}</p>
                                @endif
                                <p class="mt-1 text-xs text-amber-500">Raised {{ $signal->created_at?->diffForHumans() }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
                @foreach($resolved as $signal)
                    <div class="rounded-xl border border-green-200 bg-green-50 p-4">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-200 text-green-700 text-xs font-bold">✓</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-green-900">{{ $signal->signal_text }}</p>
                                @if($signal->resolution_note)
                                    <p class="mt-1 text-xs text-green-700">{{ $signal->resolution_note }}</p>
                                @endif
                                <p class="mt-1 text-xs text-green-500">Resolved {{ $signal->resolved_at?->diffForHumans() }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

    @elseif($activeTab === 'suggestions')
        <div class="space-y-4">
            {{-- Header with analyze button --}}
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-700">AI Workflow Optimization Suggestions</h3>
                    <p class="mt-0.5 text-xs text-gray-500">Identify parallelization opportunities, costly steps, and skill swap candidates.</p>
                </div>
                <button wire:click="analyzeSuggestions" wire:loading.attr="disabled"
                    class="flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60">
                    <svg wire:loading wire:target="analyzeSuggestions" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <svg wire:loading.remove wire:target="analyzeSuggestions" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.346.346a3.001 3.001 0 01-4.243 0l-.346-.346a5 5 0 010-7.072z"/>
                    </svg>
                    <span wire:loading.remove wire:target="analyzeSuggestions">Analyze Workflow</span>
                    <span wire:loading wire:target="analyzeSuggestions">Analyzing...</span>
                </button>
            </div>

            @if(session()->has('message'))
                <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
            @endif

            @if(empty($workflowSuggestions) && !$loadingSuggestions)
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-10 text-center">
                    <svg class="mx-auto h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <p class="mt-2 text-sm text-gray-500">Click "Analyze Workflow" to get AI-powered optimization suggestions.</p>
                </div>
            @else
                @foreach($workflowSuggestions as $index => $suggestion)
                    @php
                        $typeColor = match($suggestion['type'] ?? '') {
                            'parallelize' => 'blue',
                            'replace_skill' => 'purple',
                            'switch_model' => 'orange',
                            default => 'gray',
                        };
                        $typeLabel = match($suggestion['type'] ?? '') {
                            'parallelize' => 'Parallelize',
                            'replace_skill' => 'Replace Skill',
                            'switch_model' => 'Switch Model',
                            default => ucfirst($suggestion['type'] ?? 'Suggestion'),
                        };
                    @endphp
                    <div class="rounded-xl border border-gray-200 bg-white p-5" wire:key="suggestion-{{ $index }}">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ $typeColor === 'blue' ? 'bg-blue-100 text-blue-700' : ($typeColor === 'purple' ? 'bg-purple-100 text-purple-700' : ($typeColor === 'orange' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-700')) }}">
                                        {{ $typeLabel }}
                                    </span>
                                    @if(!empty($suggestion['expected_improvement']))
                                        <span class="text-xs font-medium text-green-600">{{ $suggestion['expected_improvement'] }}</span>
                                    @endif
                                </div>
                                <p class="mt-2 text-sm text-gray-700">{{ $suggestion['reason'] ?? '' }}</p>
                                @if(!empty($suggestion['current_value']) || !empty($suggestion['suggested_value']))
                                    <div class="mt-2 flex items-center gap-2 text-xs">
                                        <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-gray-600">{{ $suggestion['current_value'] ?? '—' }}</span>
                                        <svg class="h-3 w-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                        <span class="rounded bg-green-100 px-2 py-0.5 font-mono text-green-700">{{ $suggestion['suggested_value'] ?? '—' }}</span>
                                    </div>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                <button wire:click="createProposalFromSuggestion({{ $index }})"
                                    class="rounded-lg border border-primary-300 px-3 py-1.5 text-xs font-medium text-primary-700 hover:bg-primary-50">
                                    Create Proposal
                                </button>
                                <button wire:click="dismissSuggestion({{ $index }})"
                                    class="rounded p-1 text-gray-400 hover:text-gray-600">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    @endif

    {{-- Plugin extension point: inject custom content into experiment detail --}}
    @stack('fleet.experiment.detail')
</div>
