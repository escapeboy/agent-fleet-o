<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentTask;
use InvalidArgumentException;

class RetryExperimentAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(Experiment $experiment, ?string $actorId = null): Experiment
    {
        $retryState = match ($experiment->status) {
            ExperimentStatus::ScoringFailed => ExperimentStatus::Scoring,
            ExperimentStatus::PlanningFailed => ExperimentStatus::Planning,
            ExperimentStatus::BuildingFailed => ExperimentStatus::Building,
            ExperimentStatus::ExecutionFailed => ExperimentStatus::Executing,
            default => throw new InvalidArgumentException(
                "Cannot retry experiment [{$experiment->id}]: not in a failed state ({$experiment->status->value})."
            ),
        };

        $stageKey = $this->statusToStageKey($retryState);

        // Reset the failed stage so findOrCreateStage() creates a fresh one
        ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', $stageKey)
            ->where('iteration', $experiment->current_iteration)
            ->where('status', StageStatus::Failed)
            ->delete();

        // Delete old tasks for this stage so RunBuildingStage can create new ones
        ExperimentTask::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', $stageKey)
            ->delete();

        return $this->transition->execute(
            experiment: $experiment,
            toState: $retryState,
            reason: 'Manual retry from admin panel',
            actorId: $actorId,
        );
    }

    private function statusToStageKey(ExperimentStatus $status): string
    {
        return match ($status) {
            ExperimentStatus::Scoring => 'scoring',
            ExperimentStatus::Planning => 'planning',
            ExperimentStatus::Building => 'building',
            ExperimentStatus::Executing => 'executing',
            default => $status->value,
        };
    }
}
