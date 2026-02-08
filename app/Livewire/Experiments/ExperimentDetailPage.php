<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class ExperimentDetailPage extends Component
{
    public Experiment $experiment;

    public string $activeTab = 'timeline';

    public bool $showKillConfirm = false;

    public function mount(Experiment $experiment): void
    {
        $this->experiment = $experiment;
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

    public function killExperiment(): void
    {
        $action = app(KillExperimentAction::class);
        $action->execute($this->experiment, auth()->id(), 'Killed from admin panel');
        $this->experiment = $this->experiment->fresh();
        $this->showKillConfirm = false;
    }

    public function render()
    {
        $this->experiment->loadCount(['stages', 'artifacts', 'outboundProposals', 'metrics', 'stateTransitions']);

        return view('livewire.experiments.experiment-detail-page')
            ->layout('layouts.app', ['header' => $this->experiment->title]);
    }
}
