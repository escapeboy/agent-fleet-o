<?php

namespace App\Livewire\Experiments;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Actions\ResumeFromCheckpointAction;
use App\Domain\Experiment\Actions\RetryExperimentAction;
use App\Domain\Experiment\Actions\SteerExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\UncertaintySignal;
use App\Domain\Experiment\Models\WorklogEntry;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Domain\Workflow\Actions\SuggestWorkflowAction;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

class ExperimentDetailPage extends Component
{
    public Experiment $experiment;

    public string $activeTab = 'timeline';

    public bool $showKillConfirm = false;

    public bool $showRetryConfirm = false;

    public bool $showResumeCheckpointConfirm = false;

    public bool $showShareModal = false;

    public bool $showSteerModal = false;

    public string $steeringMessage = '';

    public bool $shareShowCosts = false;

    public bool $shareShowStages = true;

    public bool $shareShowOutputs = true;

    public string $shareExpiresAt = '';

    /** @var array<int, array> */
    public array $workflowSuggestions = [];

    public bool $loadingSuggestions = false;

    public function mount(Experiment $experiment): void
    {
        $this->experiment = $experiment;

        // Workflow experiments don't have a Timeline tab — default to Tasks
        if ($experiment->hasWorkflow()) {
            $this->activeTab = 'tasks';
        }

        // Auto-select Tasks tab when experiment has tasks and is building
        if ($experiment->tasks()->exists() && in_array($experiment->status, [
            ExperimentStatus::Building,
            ExperimentStatus::BuildingFailed,
        ])) {
            $this->activeTab = 'tasks';
        }
    }

    public function startExperiment(): void
    {
        if ($this->experiment->status !== ExperimentStatus::Draft) {
            return;
        }

        $transition = app(TransitionExperimentAction::class);
        $transition->execute(
            experiment: $this->experiment,
            toState: ExperimentStatus::Scoring,
            reason: 'Manually started from admin panel',
            actorId: auth()->id(),
        );

        $this->experiment = $this->experiment->fresh();
    }

    public function pauseExperiment(): void
    {
        $action = app(PauseExperimentAction::class);
        $action->execute($this->experiment, auth()->id());
        $this->experiment = $this->experiment->fresh();
    }

    public function resumeExperiment(): void
    {
        $action = app(ResumeExperimentAction::class);
        $action->execute($this->experiment, auth()->id());
        $this->experiment = $this->experiment->fresh();
    }

    public function openSteerModal(): void
    {
        $this->steeringMessage = '';
        $this->showSteerModal = true;
    }

    public function closeSteerModal(): void
    {
        $this->showSteerModal = false;
        $this->steeringMessage = '';
        $this->resetValidation('steeringMessage');
    }

    public function submitSteering(): void
    {
        $this->validate([
            'steeringMessage' => 'required|string|min:1|max:2000',
        ], [], ['steeringMessage' => 'steering message']);

        try {
            app(SteerExperimentAction::class)->execute(
                experiment: $this->experiment,
                message: $this->steeringMessage,
                userId: auth()->id(),
            );

            $this->experiment = $this->experiment->fresh();
            $this->showSteerModal = false;
            $this->steeringMessage = '';
            session()->flash('message', 'Steering message queued — it will be injected on the next LLM call.');
        } catch (\InvalidArgumentException $e) {
            $this->addError('steeringMessage', $e->getMessage());
        }
    }

    public function retryExperiment(): void
    {
        $action = app(RetryExperimentAction::class);
        $action->execute($this->experiment, auth()->id());
        $this->experiment = $this->experiment->fresh();
        $this->showRetryConfirm = false;
    }

    public function killExperiment(): void
    {
        $action = app(KillExperimentAction::class);
        $action->execute($this->experiment, auth()->id(), 'Killed from admin panel');
        $this->experiment = $this->experiment->fresh();
        $this->showKillConfirm = false;
    }

    public function resumeFromCheckpoint(): void
    {
        $result = app(ResumeFromCheckpointAction::class)->execute($this->experiment);
        $this->experiment = $this->experiment->fresh();
        $this->showResumeCheckpointConfirm = false;

        if (! $result['resumed']) {
            session()->flash('error', $result['message']);
        }
    }

    public function openShareModal(): void
    {
        $config = $this->experiment->share_config ?? [];
        $this->shareShowCosts = (bool) ($config['show_costs'] ?? false);
        $this->shareShowStages = (bool) ($config['show_stages'] ?? true);
        $this->shareShowOutputs = (bool) ($config['show_outputs'] ?? true);
        $this->shareExpiresAt = $config['expires_at'] ?? '';
        $this->showShareModal = true;
    }

    public function generateShareToken(): void
    {
        $this->experiment->generateShareToken();
        $this->experiment = $this->experiment->fresh();
        $this->updateShareConfig();
    }

    public function updateShareConfig(): void
    {
        $this->experiment->update([
            'share_config' => [
                'show_costs' => $this->shareShowCosts,
                'show_stages' => $this->shareShowStages,
                'show_outputs' => $this->shareShowOutputs,
                'expires_at' => $this->shareExpiresAt ?: null,
            ],
        ]);
        $this->experiment = $this->experiment->fresh();
        session()->flash('share_saved', true);
    }

    public function revokeShare(): void
    {
        $this->experiment->update([
            'share_token' => null,
            'share_enabled' => false,
        ]);
        $this->experiment = $this->experiment->fresh();
        $this->showShareModal = false;
    }

    public function analyzeSuggestions(): void
    {
        $this->loadingSuggestions = true;
        $this->workflowSuggestions = [];

        try {
            $this->workflowSuggestions = app(SuggestWorkflowAction::class)->execute($this->experiment);
        } finally {
            $this->loadingSuggestions = false;
        }
    }

    public function dismissSuggestion(int $index): void
    {
        unset($this->workflowSuggestions[$index]);
        $this->workflowSuggestions = array_values($this->workflowSuggestions);
    }

    public function createProposalFromSuggestion(int $index): void
    {
        $suggestion = $this->workflowSuggestions[$index] ?? null;

        if (! $suggestion) {
            return;
        }

        app(SuggestWorkflowAction::class)->createProposal($this->experiment, $suggestion, auth()->id());

        $this->dismissSuggestion($index);
        session()->flash('message', 'Evolution proposal created successfully.');
    }

    /**
     * Returns the current UX pipeline phase (1, 2, or 3) based on the
     * experiment's status, collapsing the 20-state machine into 3 user-facing
     * phases: Define Goal → Execute Plan → Review Results.
     */
    public function getPipelinePhase(): int
    {
        return match ($this->experiment->status) {
            ExperimentStatus::Draft,
            ExperimentStatus::SignalDetected => 1,

            ExperimentStatus::Completed,
            ExperimentStatus::Killed,
            ExperimentStatus::Discarded => 3,

            // All remaining states (active, failed, paused, expired) are phase 2.
            default => 2,
        };
    }

    /**
     * Returns the label for the current pipeline phase.
     */
    public function getPipelineLabel(): string
    {
        return match ($this->getPipelinePhase()) {
            1 => 'Define Goal',
            2 => 'Execute Plan',
            3 => 'Review Results',
            default => '',
        };
    }

    public function render(): View
    {
        $this->experiment->loadCount(['stages', 'artifacts', 'outboundProposals', 'metrics', 'stateTransitions', 'tasks', 'playbookSteps', 'children']);

        $hasOrchestration = $this->experiment->parent_experiment_id !== null || $this->experiment->children_count > 0;

        $reasoningRuns = $this->activeTab === 'reasoning'
            ? AiRun::withoutGlobalScopes()
                ->where('experiment_id', $this->experiment->id)
                ->where('has_reasoning', true)
                ->orderByDesc('created_at')
                ->get()
            : collect();

        $reasoningCount = AiRun::withoutGlobalScopes()
            ->where('experiment_id', $this->experiment->id)
            ->where('has_reasoning', true)
            ->count();

        // Load failure lessons only when the experiment is in a failed state and
        // the "lessons" tab is active (or the experiment is a known terminal failure).
        $failureLessons = $this->loadFailureLessons();

        $worklogs = $this->activeTab === 'worklog'
            ? WorklogEntry::where('workloggable_type', Experiment::class)
                ->where('workloggable_id', $this->experiment->id)
                ->latest('created_at')
                ->get()
            : collect();

        $worklogCount = WorklogEntry::where('workloggable_type', Experiment::class)
            ->where('workloggable_id', $this->experiment->id)
            ->count();

        $uncertaintySignals = $this->activeTab === 'uncertainty'
            ? UncertaintySignal::whereHas(
                'experimentStage',
                fn ($q) => $q->where('experiment_id', $this->experiment->id),
            )->latest()->get()
            : collect();

        $uncertaintyCount = UncertaintySignal::whereHas(
            'experimentStage',
            fn ($q) => $q->where('experiment_id', $this->experiment->id),
        )->count();

        return view('livewire.experiments.experiment-detail-page', [
            'hasOrchestration' => $hasOrchestration,
            'reasoningRuns' => $reasoningRuns,
            'reasoningCount' => $reasoningCount,
            'failureLessons' => $failureLessons,
            'worklogs' => $worklogs,
            'worklogCount' => $worklogCount,
            'uncertaintySignals' => $uncertaintySignals,
            'uncertaintyCount' => $uncertaintyCount,
        ])->layout('layouts.app', ['header' => $this->experiment->title]);
    }

    /**
     * Load failure-tier memory records linked to this experiment.
     *
     * Returns an empty collection when the experiment has not failed, or when
     * memory is disabled. The "Lessons Learned" tab only renders when isFailed().
     *
     * @return Collection<int, Memory>
     */
    private function loadFailureLessons(): Collection
    {
        if (! $this->experiment->status->isFailed() || ! config('memory.enabled', true)) {
            return collect();
        }

        return Memory::withoutGlobalScopes()
            ->where('source_type', 'experiment')
            ->where('source_id', $this->experiment->id)
            ->where('tier', MemoryTier::Failures)
            ->orderByDesc('created_at')
            ->get(['id', 'content', 'metadata', 'created_at']);
    }
}
