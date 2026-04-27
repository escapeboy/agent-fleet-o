<?php

namespace App\Domain\Shared\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Shared\Events\TeamActivityBroadcast;

class BroadcastExperimentTransitioned
{
    private const SURFACE_STATES = [
        'completed', 'failed', 'killed', 'discarded', 'awaiting_approval',
        'rejected', 'iterating',
    ];

    public function handle(ExperimentTransitioned $event): void
    {
        if (! in_array($event->toState->value, self::SURFACE_STATES, true)) {
            return;
        }

        $teamId = $event->experiment->team_id;
        if (! $teamId) {
            return;
        }

        TeamActivityBroadcast::dispatch(
            teamId: $teamId,
            kind: 'experiment.transitioned',
            actorId: $event->experiment->id,
            actorKind: 'experiment',
            actorLabel: $event->experiment->title ?: 'Experiment',
            summary: "{$event->fromState->value} → {$event->toState->value}",
            at: now()->toIso8601String(),
            extra: [
                'experiment_id' => $event->experiment->id,
                'from_state' => $event->fromState->value,
                'to_state' => $event->toState->value,
            ],
        );
    }
}
