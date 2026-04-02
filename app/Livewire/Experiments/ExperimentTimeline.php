<?php

namespace App\Livewire\Experiments;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStateTransition;
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

        $stageRuns = AiRun::withoutGlobalScopes()
            ->where('experiment_id', $this->experiment->id)
            ->whereNotNull('experiment_stage_id')
            ->get()
            ->groupBy('experiment_stage_id');

        $stuckTransitions = ExperimentStateTransition::withoutGlobalScopes()
            ->where('experiment_id', $this->experiment->id)
            ->where('to_state', 'paused')
            ->whereNotNull('metadata')
            ->get();

        return view('livewire.experiments.experiment-timeline', [
            'stages' => $stages,
            'contextHealth' => $contextHealth,
            'stageRuns' => $stageRuns,
            'stuckTransitions' => $stuckTransitions,
        ]);
    }
}
