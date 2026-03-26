<?php

namespace App\Domain\Memory\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Memory\Jobs\ExtractSuccessPatternJob;

/**
 * Dispatches success pattern extraction when an experiment reaches Completed status.
 *
 * Only Completed experiments are processed — CollectingMetrics, Evaluating, and
 * Iterating are intermediate states where the final outcome is not yet confirmed.
 */
class ExtractSuccessPatternListener
{
    public function handle(ExperimentTransitioned $event): void
    {
        if (! config('memory.enabled', true)) {
            return;
        }

        if ($event->toState !== ExperimentStatus::Completed) {
            return;
        }

        ExtractSuccessPatternJob::dispatch(
            $event->experiment->id,
            $event->experiment->team_id,
        );
    }
}
