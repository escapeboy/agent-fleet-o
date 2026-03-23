<?php

namespace App\Domain\Memory\Listeners;

use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Memory\Jobs\ExtractFailureLessonJob;

/**
 * Dispatches failure lesson extraction when an experiment enters a failed state.
 *
 * Failed states: ScoringFailed, PlanningFailed, BuildingFailed, ExecutionFailed.
 * Killed and Discarded experiments are skipped — those are intentional stops,
 * not actionable failures.
 */
class ExtractFailureLessonListener
{
    public function handle(ExperimentTransitioned $event): void
    {
        if (! config('memory.enabled', true)) {
            return;
        }

        if (! $event->toState->isFailed()) {
            return;
        }

        ExtractFailureLessonJob::dispatch(
            $event->experiment->id,
            $event->experiment->team_id,
        );
    }
}
