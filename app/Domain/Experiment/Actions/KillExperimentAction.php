<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;

class KillExperimentAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(Experiment $experiment, ?string $actorId = null, string $reason = 'Manual kill'): Experiment
    {
        return $this->transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Killed,
            reason: $reason,
            actorId: $actorId,
        );
    }
}
