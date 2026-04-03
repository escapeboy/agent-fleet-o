<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\PlaybookExecutor;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Log;

class ResumeFromCheckpointAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transitionAction,
    ) {}

    /**
     * Resume an experiment from its most recent checkpoint.
     *
     * Unlike RetryFromStepAction (which resets progress), this preserves
     * all checkpoint_data and simply re-triggers execution so the pipeline
     * can pick up from where it left off.
     *
     * @return array{resumed: bool, step_id: string|null, message: string}
     */
    public function execute(Experiment $experiment): array
    {
        $allowedStatuses = [
            ExperimentStatus::Executing,
            ExperimentStatus::ScoringFailed,
            ExperimentStatus::PlanningFailed,
            ExperimentStatus::BuildingFailed,
        ];

        if (! in_array($experiment->status, $allowedStatuses)) {
            return [
                'resumed' => false,
                'step_id' => null,
                'message' => "Cannot resume from checkpoint: experiment is in state '{$experiment->status->value}'.",
            ];
        }

        // Find the most recent step with checkpoint data
        $checkpointStep = PlaybookStep::where('experiment_id', $experiment->id)
            ->whereNotNull('checkpoint_data')
            ->where('status', 'running')
            ->orderByDesc('updated_at')
            ->first();

        // Fallback: any step with checkpoint data that hasn't completed
        if (! $checkpointStep) {
            $checkpointStep = PlaybookStep::where('experiment_id', $experiment->id)
                ->whereNotNull('checkpoint_data')
                ->whereNotIn('status', ['completed', 'skipped'])
                ->orderByDesc('updated_at')
                ->first();
        }

        if (! $checkpointStep) {
            return [
                'resumed' => false,
                'step_id' => null,
                'message' => 'No checkpoint data found for this experiment. Use retry instead.',
            ];
        }

        Log::info('ResumeFromCheckpointAction: Resuming from checkpoint', [
            'experiment_id' => $experiment->id,
            'step_id' => $checkpointStep->id,
            'step_order' => $checkpointStep->order,
            'step_status' => $checkpointStep->status,
        ]);

        // Mark the step back to pending so the pipeline re-dispatches it
        // The checkpoint_data is preserved — the pipeline reads it on restart
        $checkpointStep->update(['status' => 'pending']);

        // Ensure experiment is in Executing state before re-triggering
        if ($experiment->status !== ExperimentStatus::Executing) {
            $this->transitionAction->execute(
                $experiment,
                ExperimentStatus::Executing,
                'Resumed from checkpoint',
            );
        } else {
            // Re-trigger the appropriate executor
            if ($experiment->hasWorkflow()) {
                app(WorkflowGraphExecutor::class)->execute($experiment);
            } else {
                app(PlaybookExecutor::class)->execute($experiment);
            }
        }

        return [
            'resumed' => true,
            'step_id' => $checkpointStep->id,
            'message' => "Resumed from checkpoint at step #{$checkpointStep->order}. Checkpoint data preserved.",
        ];
    }
}
