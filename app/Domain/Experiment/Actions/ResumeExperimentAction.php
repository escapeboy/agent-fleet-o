<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use InvalidArgumentException;

class ResumeExperimentAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transition,
    ) {}

    public function execute(Experiment $experiment, ?string $actorId = null): Experiment
    {
        if ($experiment->status !== ExperimentStatus::Paused) {
            throw new InvalidArgumentException(
                "Cannot resume experiment [{$experiment->id}]: not in paused state."
            );
        }

        $resumeState = $experiment->paused_from_status
            ? ExperimentStatus::from($experiment->paused_from_status)
            : ExperimentStatus::Draft;

        $result = $this->transition->execute(
            experiment: $experiment,
            toState: $resumeState,
            reason: 'Resumed',
            actorId: $actorId,
        );

        $result->update(['paused_from_status' => null]);

        return $result->fresh();
    }
}
