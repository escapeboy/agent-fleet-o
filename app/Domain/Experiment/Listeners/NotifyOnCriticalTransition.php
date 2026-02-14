<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Events\ExperimentTransitioned;
use Illuminate\Support\Facades\Log;

class NotifyOnCriticalTransition
{
    private const CRITICAL_STATES = [
        'awaiting_approval',
        'completed',
        'killed',
        'expired',
    ];

    public function handle(ExperimentTransitioned $event): void
    {
        if (! in_array($event->toState->value, self::CRITICAL_STATES)) {
            return;
        }

        $experiment = $event->experiment;

        Log::channel('stack')->info("CRITICAL TRANSITION: Experiment [{$experiment->title}] → {$event->toState->value}", [
            'experiment_id' => $experiment->id,
            'from_state' => $event->fromState->value,
            'to_state' => $event->toState->value,
            'user_id' => $experiment->user_id,
        ]);

        // In Phase 4+, this would send actual notifications (email, Slack, etc.)
        // For now, we log at a high level for observability
    }
}
