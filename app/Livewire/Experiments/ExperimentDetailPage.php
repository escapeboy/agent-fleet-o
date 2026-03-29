<?php

namespace App\Livewire\Experiments;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Actions\RetryExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
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

    public bool $showShareModal = false;

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

        return view('livewire.experiments.experiment-detail-page', [
            'hasOrchestration' => $hasOrchestration,
            'reasoningRuns' => $reasoningRuns,
            'reasoningCount' => $reasoningCount,
            'failureLessons' => $failureLessons,
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
