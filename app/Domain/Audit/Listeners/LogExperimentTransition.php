<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;

class LogExperimentTransition
{
    public function handle(ExperimentTransitioned $event): void
    {
        $userId = $event->experiment->user_id;
        $triggeredBy = $userId ? 'user:'.$userId : 'agent';

        // Transitions triggered by the pipeline executor (AI-driven stages)
        $aiDrivenStates = ['scoring', 'planning', 'building', 'executing', 'collecting_metrics', 'evaluating', 'iterating'];
        if (! $userId && in_array($event->toState->value, $aiDrivenStates)) {
            $triggeredBy = 'agent';
        } elseif (! $userId) {
            $triggeredBy = 'scheduler';
        }

        AuditEntry::withoutGlobalScopes()->create([
            'user_id' => $userId,
            'team_id' => $event->experiment->team_id,
            'event' => 'experiment.transitioned',
            'subject_type' => Experiment::class,
            'subject_id' => $event->experiment->id,
            'properties' => [
                'from_state' => $event->fromState->value,
                'to_state' => $event->toState->value,
                'title' => $event->experiment->title,
            ],
            'triggered_by' => $triggeredBy,
            'created_at' => now(),
        ]);
    }
}
