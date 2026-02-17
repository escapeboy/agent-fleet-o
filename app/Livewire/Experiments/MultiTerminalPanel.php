<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Services\StepOutputBroadcaster;
use Livewire\Component;

class MultiTerminalPanel extends Component
{
    public string $experimentId;

    /** @var array<string, string> stepId => accumulated output */
    public array $outputs = [];

    /** @var array<array{id: string, label: string}> */
    public array $tabs = [];

    public function mount(string $experimentId): void
    {
        $this->experimentId = $experimentId;
        $this->loadTabs();
    }

    public function loadTabs(): void
    {
        $stages = ExperimentStage::where('experiment_id', $this->experimentId)
            ->orderBy('created_at')
            ->get();

        // Also load playbook steps if they exist
        $experiment = Experiment::find($this->experimentId);
        $playbookSteps = $experiment?->playbookSteps()
            ->whereNotNull('started_at')
            ->orderBy('order')
            ->get() ?? collect();

        $this->tabs = [];

        foreach ($stages as $stage) {
            $this->tabs[] = [
                'id' => $stage->id,
                'label' => str_replace('_', ' ', ucfirst($stage->stage->value)).' #'.$stage->iteration,
            ];
        }

        foreach ($playbookSteps as $step) {
            $this->tabs[] = [
                'id' => $step->id,
                'label' => $step->name ?? 'Step '.$step->order,
            ];
        }
    }

    public function pollOutputs(): void
    {
        $broadcaster = app(StepOutputBroadcaster::class);

        foreach ($this->tabs as $tab) {
            $accumulated = $broadcaster->getAccumulatedOutput($tab['id']);
            if ($accumulated && ($this->outputs[$tab['id']] ?? '') !== $accumulated) {
                $this->outputs[$tab['id']] = $accumulated;
                $this->dispatch('multi-terminal-output', id: $tab['id'], output: $accumulated);
            }
        }

        // Check for new tabs
        $currentIds = array_column($this->tabs, 'id');
        $this->loadTabs();
        $newTabs = array_filter($this->tabs, fn ($t) => ! in_array($t['id'], $currentIds));

        foreach ($newTabs as $tab) {
            $this->dispatch('multi-terminal-new-tab', id: $tab['id'], label: $tab['label']);
        }
    }

    public function render()
    {
        return view('livewire.experiments.multi-terminal-panel');
    }
}
