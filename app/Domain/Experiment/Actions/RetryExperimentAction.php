<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
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

        return $this->transition->execute(
            experiment: $experiment,
            toState: $retryState,
            reason: 'Manual retry from admin panel',
            actorId: $actorId,
        );
    }
}
