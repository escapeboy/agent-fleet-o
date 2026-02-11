<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Metrics\Models\Metric;

class RecordTransitionMetrics
{
    public function handle(ExperimentTransitioned $event): void
    {
        // Record the transition as a metric for timing/duration analysis
        $experiment = $event->experiment;

        // Calculate time in previous state
        $previousTransition = $experiment->stateTransitions()
            ->where('to_state', $event->fromState->value)
            ->latest('created_at')
            ->first();

        $durationSeconds = $previousTransition
            ? abs(now()->diffInSeconds($previousTransition->created_at))
            : 0;

        Metric::withoutGlobalScopes()->create([
            'experiment_id' => $experiment->id,
            'team_id' => $experiment->team_id,
            'type' => 'state_duration',
            'value' => $durationSeconds,
            'source' => 'state_machine',
            'metadata' => [
                'from_state' => $event->fromState->value,
                'to_state' => $event->toState->value,
                'iteration' => $experiment->current_iteration,
            ],
            'occurred_at' => now(),
            'recorded_at' => now(),
        ]);
    }
}
