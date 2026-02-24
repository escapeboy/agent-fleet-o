<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Log;

class ResumeParentOnSubWorkflowComplete
{
    public function handle(ExperimentTransitioned $event): void
    {
        $child = $event->experiment;

        // Only act when a child experiment terminates
        if (! $event->toState->isTerminal()) {
            return;
        }

        // Must have a parent_step_id in constraints (set by DispatchSubWorkflowAction)
        $parentStepId = $child->constraints['parent_step_id'] ?? null;

        if (! $parentStepId) {
            return;
        }

        $step = PlaybookStep::find($parentStepId);

        if (! $step || $step->status !== 'running') {
            return;
        }

        $parentExperiment = Experiment::withoutGlobalScopes()->find($child->parent_experiment_id);

        if (! $parentExperiment || $parentExperiment->status->isTerminal()) {
            // Mark step failed if parent is dead
            $step->update([
                'status' => 'failed',
                'error_message' => 'Parent experiment terminated while sub-workflow was running',
                'completed_at' => now(),
            ]);

            return;
        }

        $nodeId = $child->constraints['parent_node_id'] ?? $step->workflow_node_id;
        $isSuccess = $event->toState->value === 'completed';

        Log::info('ResumeParentOnSubWorkflowComplete: child terminated, resuming parent workflow', [
            'child_id' => $child->id,
            'parent_id' => $parentExperiment->id,
            'step_id' => $step->id,
            'child_status' => $event->toState->value,
        ]);

        $step->update([
            'status' => $isSuccess ? 'completed' : 'failed',
            'output' => array_merge($step->output ?? [], [
                'child_experiment_id' => $child->id,
                'child_status' => $event->toState->value,
                'child_completed_at' => now()->toIso8601String(),
            ]),
            'error_message' => $isSuccess ? null : "Sub-workflow ended with status: {$event->toState->value}",
            'completed_at' => now(),
        ]);

        if ($isSuccess) {
            try {
                app(WorkflowGraphExecutor::class)->continueAfterBatch($parentExperiment, [$nodeId]);
            } catch (\Throwable $e) {
                Log::error('ResumeParentOnSubWorkflowComplete: failed to continue parent', [
                    'parent_id' => $parentExperiment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
