<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Actions\CollectWorkflowArtifactsAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use Illuminate\Support\Facades\Log;

class CollectWorkflowArtifactsOnCompletion
{
    public function handle(ExperimentTransitioned $event): void
    {
        // Only when workflow finishes (Completed or CollectingMetrics)
        if (! in_array($event->toState, [ExperimentStatus::Completed, ExperimentStatus::CollectingMetrics])) {
            return;
        }

        $experiment = $event->experiment;

        // Only for experiments with playbook steps (workflow or legacy)
        if (! $experiment->playbookSteps()->exists()) {
            return;
        }

        // Idempotency — skip if artifacts already exist
        if ($experiment->artifacts()->exists()) {
            Log::debug('CollectWorkflowArtifactsOnCompletion: Artifacts already exist, skipping', [
                'experiment_id' => $experiment->id,
            ]);

            return;
        }

        try {
            app(CollectWorkflowArtifactsAction::class)->execute($experiment);
        } catch (\Throwable $e) {
            Log::error('CollectWorkflowArtifactsOnCompletion: Failed to collect artifacts', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
