<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;

class PauseExperimentAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(Experiment $experiment, ?string $actorId = null, string $reason = 'Manual pause'): Experiment
    {
        $experiment->update([
            'paused_from_status' => $experiment->status->value,
        ]);

        return $this->transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Paused,
            reason: $reason,
            actorId: $actorId,
        );
    }
}
