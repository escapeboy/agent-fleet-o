<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\DTOs\ContextHealthDTO;
use App\Infrastructure\AI\Services\ContextHealthService;
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

        /** @var ContextHealthDTO|null $contextHealth */
        $contextHealth = null;

        try {
            $contextHealth = app(ContextHealthService::class)
                ->getExperimentContextHealth($this->experiment);
        } catch (\Throwable) {
            // Non-critical display feature — silently skip if unavailable.
        }

        return view('livewire.experiments.experiment-timeline', [
            'stages' => $stages,
            'contextHealth' => $contextHealth,
        ]);
    }
}
