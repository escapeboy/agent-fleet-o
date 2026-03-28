<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Support\Facades\DB;
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

        // ── Fan-out branch (DynamicForkFanOutAction children) ─────────────────
        $forkParentStepId = $child->constraints['fork_parent_step_id'] ?? null;

        if ($forkParentStepId) {
            $this->handleForkChild($child, $event, $forkParentStepId);

            return;
        }

        // ── Single sub-workflow branch (DispatchSubWorkflowAction) ────────────
        $parentStepId = $child->constraints['parent_step_id'] ?? null;

        if (! $parentStepId) {
            return;
        }

        $step = PlaybookStep::whereHas('experiment', fn ($q) => $q->where('team_id', $child->team_id))
            ->find($parentStepId);

        if (! $step || $step->status !== 'running') {
            return;
        }

        $parentExperiment = Experiment::withoutGlobalScopes()->find($child->parent_experiment_id);

        if (! $parentExperiment || $parentExperiment->status->isTerminal()) {
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

    /**
     * Handle a fan-out child completing (from DynamicForkFanOutAction).
     * Uses a pessimistic lock to safely increment the done counter.
     * When all branches complete, the template step is marked complete.
     */
    private function handleForkChild(Experiment $child, ExperimentTransitioned $event, string $forkParentStepId): void
    {
        $allDone = DB::transaction(function () use ($child, $event, $forkParentStepId): bool {
            $step = PlaybookStep::lockForUpdate()
                ->whereHas('experiment', fn ($q) => $q->where('team_id', $child->team_id))
                ->find($forkParentStepId);

            if (! $step || $step->status !== 'running') {
                return false;
            }

            $output = $step->output ?? [];
            $total = (int) ($output['fork_children_total'] ?? 0);
            $done = (int) ($output['fork_children_done'] ?? 0);
            $results = $output['fork_results'] ?? [];
            $isSuccess = $event->toState->value === 'completed';

            $results[$child->id] = [
                'status' => $event->toState->value,
                'completed_at' => now()->toIso8601String(),
                'fork_item_index' => $child->constraints['fork_item_index'] ?? null,
            ];

            $done++;
            $allDone = $done >= $total;

            $step->update([
                'output' => array_merge($output, [
                    'fork_children_done' => $done,
                    'fork_results' => $results,
                ]),
                ...($allDone ? [
                    'status' => $isSuccess ? 'completed' : 'failed',
                    'error_message' => $isSuccess ? null : "Fork branch ended with status: {$event->toState->value}",
                    'completed_at' => now(),
                ] : []),
            ]);

            return $allDone && $isSuccess;
        });

        if (! $allDone) {
            return;
        }

        // All branches done — continue the parent workflow
        $step = PlaybookStep::find($forkParentStepId);
        $parentExperiment = $step
            ? Experiment::withoutGlobalScopes()->find(
                Experiment::withoutGlobalScopes()
                    ->where('id', $child->parent_experiment_id)
                    ->value('id'),
            )
            : null;

        if (! $parentExperiment || $parentExperiment->status->isTerminal()) {
            return;
        }

        $nodeId = $child->constraints['fork_parent_node_id'] ?? $step?->workflow_node_id;

        Log::info('ResumeParentOnSubWorkflowComplete: all fork branches complete, resuming parent', [
            'parent_experiment_id' => $parentExperiment->id,
            'step_id' => $forkParentStepId,
            'node_id' => $nodeId,
        ]);

        try {
            app(WorkflowGraphExecutor::class)->continueAfterBatch($parentExperiment, [$nodeId]);
        } catch (\Throwable $e) {
            Log::error('ResumeParentOnSubWorkflowComplete: failed to continue parent after fork', [
                'parent_id' => $parentExperiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
