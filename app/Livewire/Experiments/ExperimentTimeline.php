<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class ExperimentTimeline extends Component
{
    public Experiment $experiment;
    public ?string $expandedStageId = null;

    public function toggleStage(string $stageId): void
    {
        $this->expandedStageId = $this->expandedStageId === $stageId ? null : $stageId;
    }

    public function render()
    {
        $stages = $this->experiment->stages()
            ->orderBy('created_at')
            ->get();

        return view('livewire.experiments.experiment-timeline', [
            'stages' => $stages,
        ]);
    }
}
