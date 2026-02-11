<?php

namespace App\Domain\Project\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Project\Models\ProjectRun;

class LogProjectActivity
{
    public function handle(ExperimentTransitioned $event): void
    {
        // Only log terminal states for project runs
        if (! $event->toState->isTerminal() && ! $event->toState->isFailed()) {
            return;
        }

        $run = ProjectRun::where('experiment_id', $event->experiment->id)->first();

        if (! $run) {
            return;
        }

        activity()
            ->performedOn($run->project)
            ->withProperties([
                'run_id' => $run->id,
                'run_number' => $run->run_number,
                'experiment_id' => $event->experiment->id,
                'from_state' => $event->fromState->value,
                'to_state' => $event->toState->value,
                'trigger' => $run->trigger,
                'spend_credits' => $run->spend_credits,
            ])
            ->log($event->toState === ExperimentStatus::Completed ? 'project.run.completed' : 'project.run.failed');
    }
}
