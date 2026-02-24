<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchSubWorkflowAction
{
    public function __construct(
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
        private readonly MaterializeWorkflowAction $materialize,
    ) {}

    /**
     * Spawn a child experiment for a sub-workflow node and put the step in "running" state.
     *
     * When the child experiment terminates, ResumeParentOnSubWorkflowComplete
     * marks this step completed and calls continueAfterBatch on the parent.
     */
    public function execute(PlaybookStep $step, Experiment $parent, array $nodeData): void
    {
        $subWorkflowId = $nodeData['sub_workflow_id'] ?? null;

        if (! $subWorkflowId) {
            $step->update([
                'status' => 'failed',
                'error_message' => 'Sub-workflow node is missing sub_workflow_id configuration',
                'completed_at' => now(),
            ]);

            return;
        }

        $subWorkflow = Workflow::find($subWorkflowId);

        if (! $subWorkflow) {
            $step->update([
                'status' => 'failed',
                'error_message' => "Sub-workflow {$subWorkflowId} not found",
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            $child = DB::transaction(function () use ($step, $parent, $subWorkflow, $nodeData) {
                $child = $this->createExperiment->execute(
                    userId: $parent->user_id,
                    title: '[Sub-Workflow] '.($nodeData['label'] ?? $subWorkflow->name),
                    thesis: $subWorkflow->description ?? 'Sub-workflow execution',
                    track: $parent->track->value,
                    budgetCapCredits: $parent->budget_cap_credits
                        ? (int) ($parent->budget_cap_credits * 0.3)
                        : 2000,
                    maxIterations: 1,
                    maxOutboundCount: 0,
                    constraints: array_merge($parent->constraints ?? [], [
                        'parent_step_id' => $step->id,
                        'parent_node_id' => $step->workflow_node_id,
                        'auto_approve' => true,
                    ]),
                    teamId: $parent->team_id,
                );

                $child->update([
                    'parent_experiment_id' => $parent->id,
                    'nesting_depth' => ($parent->nesting_depth ?? 0) + 1,
                    'workflow_id' => $subWorkflow->id,
                ]);

                // Mark the parent step as running while waiting for child
                $step->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'output' => ['child_experiment_id' => $child->id],
                ]);

                // Materialize and start the sub-workflow
                $this->materialize->execute($child, $subWorkflow);

                $this->transition->execute(
                    experiment: $child,
                    toState: ExperimentStatus::Executing,
                    reason: 'Sub-workflow started by parent workflow node: '.($nodeData['label'] ?? ''),
                );

                return $child;
            });

            Log::info('DispatchSubWorkflowAction: sub-workflow spawned', [
                'parent_experiment_id' => $parent->id,
                'child_experiment_id' => $child->id,
                'step_id' => $step->id,
                'sub_workflow_id' => $subWorkflow->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('DispatchSubWorkflowAction: failed to spawn sub-workflow', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);

            $step->update([
                'status' => 'failed',
                'error_message' => 'Failed to spawn sub-workflow: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
