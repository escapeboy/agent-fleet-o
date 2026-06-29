<?php

namespace App\Domain\Shared\Listeners;

use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Shared\Events\TeamActivityBroadcast;
use Illuminate\Support\Facades\Log;

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

        // TeamActivityBroadcast is ShouldBroadcastNow → it broadcasts inline.
        // This listener runs synchronously inside the TransitionExperimentAction
        // DB transaction, so a relay/Reverb outage would throw and roll back the
        // experiment transition itself (Sentry #939/#941/#944). The live firehose
        // is best-effort UI; never let it crash the transition that fed it.
        try {
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
        } catch (\Throwable $e) {
            Log::warning('TeamActivityBroadcast failed (experiment.transitioned)', [
                'experiment_id' => $event->experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
