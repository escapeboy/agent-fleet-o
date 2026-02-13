<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Domain\Experiment\States\ExperimentStateMachine;
use App\Domain\Experiment\States\TransitionPrerequisiteValidator;
use Illuminate\Support\Facades\DB;

class TransitionExperimentAction
{
    public function __construct(
        private readonly ExperimentStateMachine $stateMachine,
        private readonly TransitionPrerequisiteValidator $prerequisiteValidator,
    ) {}

    public function execute(
        Experiment $experiment,
        ExperimentStatus $toState,
        ?string $reason = null,
        ?string $actorId = null,
        array $metadata = [],
    ): Experiment {
        return DB::transaction(function () use ($experiment, $toState, $reason, $actorId, $metadata) {
            $experiment = Experiment::withoutGlobalScopes()->lockForUpdate()->findOrFail($experiment->id);

            $fromState = $experiment->status;

            $this->stateMachine->validate($experiment, $toState);

            $prerequisiteError = $this->prerequisiteValidator->validate($experiment, $toState);

            if ($prerequisiteError) {
                throw new \InvalidArgumentException($prerequisiteError);
            }

            $experiment->update([
                'status' => $toState,
                'started_at' => $this->shouldSetStartedAt($fromState, $toState) ? now() : $experiment->started_at,
                'completed_at' => $toState === ExperimentStatus::Completed ? now() : $experiment->completed_at,
                'killed_at' => $toState === ExperimentStatus::Killed ? now() : $experiment->killed_at,
            ]);

            ExperimentStateTransition::withoutGlobalScopes()->create([
                'experiment_id' => $experiment->id,
                'team_id' => $experiment->team_id,
                'from_state' => $fromState->value,
                'to_state' => $toState->value,
                'reason' => $reason,
                'actor_id' => $actorId,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            event(new ExperimentTransitioned(
                experiment: $experiment,
                fromState: $fromState,
                toState: $toState,
            ));

            return $experiment->fresh();
        });
    }

    private function shouldSetStartedAt(ExperimentStatus $from, ExperimentStatus $to): bool
    {
        return in_array($from, [ExperimentStatus::Draft, ExperimentStatus::SignalDetected])
            && $to === ExperimentStatus::Scoring;
    }
}
