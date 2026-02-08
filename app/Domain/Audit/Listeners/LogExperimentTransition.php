<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;

class LogExperimentTransition
{
    public function handle(ExperimentTransitioned $event): void
    {
        AuditEntry::create([
            'user_id' => $event->experiment->user_id,
            'event' => 'experiment.transitioned',
            'subject_type' => Experiment::class,
            'subject_id' => $event->experiment->id,
            'properties' => [
                'from_state' => $event->fromState->value,
                'to_state' => $event->toState->value,
                'title' => $event->experiment->title,
            ],
            'created_at' => now(),
        ]);
    }
}
