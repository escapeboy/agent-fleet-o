<div wire:poll.10s>
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

            {{-- Share button --}}
            <button wire:click="openShareModal"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                {{ $experiment->share_enabled ? '🔗 Sharing On' : 'Share' }}
            </button>
        </div>
    </div>

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
        <nav class="-mb-px flex gap-6">
            @php
                $tabs = $experiment->hasWorkflow()
                    ? ['tasks' => 'Tasks', 'artifacts' => 'Artifacts', 'outbound' => 'Outbound', 'metrics' => 'Metrics', 'cost' => 'Cost', 'chain' => 'Execution Chain', 'suggestions' => 'Suggestions', 'reasoning' => 'Reasoning', 'execution-log' => 'Execution Log', 'transitions' => 'Transitions']
                    : ['timeline' => 'Timeline', 'tasks' => 'Tasks', 'artifacts' => 'Artifacts', 'outbound' => 'Outbound', 'metrics' => 'Metrics', 'cost' => 'Cost', 'reasoning' => 'Reasoning', 'execution-log' => 'Execution Log', 'transitions' => 'Transitions'];
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
                    @elseif($tab === 'chain')
                        @php $chainCount = \App\Domain\Workflow\Models\WorkflowNodeEvent::where('experiment_id', $experiment->id)->count(); @endphp
                        @if($chainCount > 0)
                            <span class="ml-1 rounded-full bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-700">{{ $chainCount }}</span>
                        @endif
                    @elseif($tab === 'reasoning' && $reasoningCount > 0)
                        <span class="ml-1 rounded-full bg-purple-100 px-1.5 py-0.5 text-xs text-purple-700">{{ $reasoningCount }}</span>
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
</div>
