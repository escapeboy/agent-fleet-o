<?php

namespace App\Domain\Evaluation\Listeners;

use App\Domain\Evaluation\Enums\EvaluationCaseSource;
use App\Domain\Evaluation\Jobs\AppendRegressionCaseJob;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Shared\Services\TeamFeatures;

/**
 * On an experiment transition to a failed state, append a deferred regression
 * case capturing the experiment's seed input and the named failure state
 * (Agentic AI Flywheel #1). Deterministic — no extra LLM call. Runs in parallel
 * to ExtractFailureLessonListener.
 */
class AppendRegressionCaseOnFailureListener
{
    public function handle(ExperimentTransitioned $event): void
    {
        if (! app(TeamFeatures::class)->enabled('auto_eval', $event->experiment->team_id)) {
            return;
        }

        if (! $event->toState->isFailed()) {
            return;
        }

        $experiment = $event->experiment;
        $input = trim((string) ($experiment->thesis ?? $experiment->title ?? ''));
        if ($input === '') {
            return;
        }

        AppendRegressionCaseJob::dispatch(
            teamId: $experiment->team_id,
            input: $input,
            failingOutput: null,
            errorModeLabel: 'experiment:'.$event->toState->value,
            source: EvaluationCaseSource::FailureLesson->value,
            metadata: [
                'experiment_id' => $experiment->id,
                'trace_id' => $experiment->id,
                'final_status' => $event->toState->value,
            ],
        );
    }
}
