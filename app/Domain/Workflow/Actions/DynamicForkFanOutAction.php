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

/**
 * Spawns one child experiment per item in the fork array.
 *
 * Called by WorkflowGraphExecutor when a DynamicFork node has
 * fork_execution_mode = 'sub_workflow'. The template node must be a
 * sub_workflow node with a valid sub_workflow_id.
 *
 * Each child receives the fork item in its constraints:
 *   constraints.fork_item         = the array element for this child
 *   constraints.fork_item_index   = 0-based position in the array
 *   constraints.fork_total        = total number of children
 *   constraints.fork_parent_step_id = the template step's ID
 *   constraints.fork_variable_name  = variable name (default: fork_item)
 *
 * The template step's output tracks progress:
 *   output.fork_children_total = N
 *   output.fork_children_done  = 0 (incremented on each child completion)
 *   output.fork_children_ids   = [child_exp_id, ...]
 *   output.fork_results        = {child_exp_id: result, ...}
 */
class DynamicForkFanOutAction
{
    public function __construct(
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
        private readonly MaterializeWorkflowAction $materialize,
    ) {}

    /**
     * @param  array<int, mixed>  $forkItems
     * @param  array<string, mixed>  $nodeData
     */
    public function execute(
        PlaybookStep $step,
        Experiment $parent,
        array $forkItems,
        string $forkVariableName,
        array $nodeData,
    ): void {
        $subWorkflowId = $nodeData['config']['sub_workflow_id']
            ?? $nodeData['sub_workflow_id']
            ?? null;

        if (! $subWorkflowId) {
            $step->update([
                'status' => 'failed',
                'error_message' => 'DynamicFork sub_workflow mode requires a sub_workflow node with sub_workflow_id configured',
                'completed_at' => now(),
            ]);

            return;
        }

        // Nesting depth guard — prevent infinite recursion
        $maxDepth = (int) config('workflow.max_nesting_depth', 5);
        if (($parent->nesting_depth ?? 0) >= $maxDepth) {
            $step->update([
                'status' => 'failed',
                'error_message' => "Max nesting depth {$maxDepth} exceeded for DynamicFork",
                'completed_at' => now(),
            ]);

            return;
        }

        // Tenant isolation — sub_workflow_id must belong to the same team
        $subWorkflow = Workflow::withoutGlobalScopes()
            ->where('team_id', $parent->team_id)
            ->find($subWorkflowId);

        if (! $subWorkflow) {
            $step->update([
                'status' => 'failed',
                'error_message' => "Sub-workflow {$subWorkflowId} not found for DynamicFork fan-out",
                'completed_at' => now(),
            ]);

            return;
        }

        $total = count($forkItems);
        $childIds = [];

        try {
            foreach ($forkItems as $index => $item) {
                $child = DB::transaction(function () use ($step, $parent, $subWorkflow, $item, $index, $total, $forkVariableName, $nodeData) {
                    $child = $this->createExperiment->execute(
                        userId: $parent->user_id,
                        title: '[Fork] '.($nodeData['label'] ?? $subWorkflow->name).' #'.($index + 1),
                        thesis: $subWorkflow->description ?? 'Fork branch execution',
                        track: $parent->track->value,
                        budgetCapCredits: $parent->budget_cap_credits
                            ? (int) ($parent->budget_cap_credits * 0.3 / $total)
                            : 1000,
                        maxIterations: 1,
                        maxOutboundCount: 0,
                        constraints: array_merge($parent->constraints ?? [], [
                            'fork_parent_step_id' => $step->id,
                            'fork_parent_node_id' => $step->workflow_node_id,
                            'fork_item' => $item,
                            'fork_item_index' => $index,
                            'fork_total' => $total,
                            'fork_variable_name' => $forkVariableName,
                            'auto_approve' => true,
                        ]),
                        teamId: $parent->team_id,
                    );

                    $child->update([
                        'parent_experiment_id' => $parent->id,
                        'nesting_depth' => ($parent->nesting_depth ?? 0) + 1,
                        'workflow_id' => $subWorkflow->id,
                    ]);

                    $this->materialize->execute($child, $subWorkflow);

                    $this->transition->execute(
                        experiment: $child,
                        toState: ExperimentStatus::Executing,
                        reason: "DynamicFork branch #{$index} started",
                    );

                    return $child;
                });

                $childIds[] = $child->id;
            }

            // Mark the template step as running while waiting for all N children
            $step->update([
                'status' => 'running',
                'started_at' => now(),
                'output' => [
                    'fork_children_total' => $total,
                    'fork_children_done' => 0,
                    'fork_children_ids' => $childIds,
                    'fork_results' => [],
                ],
            ]);

            Log::info('DynamicForkFanOutAction: spawned fan-out children', [
                'parent_experiment_id' => $parent->id,
                'step_id' => $step->id,
                'sub_workflow_id' => $subWorkflowId,
                'total_branches' => $total,
                'child_ids' => $childIds,
            ]);
        } catch (\Throwable $e) {
            Log::error('DynamicForkFanOutAction: failed to spawn fan-out', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);

            $step->update([
                'status' => 'failed',
                'error_message' => 'DynamicFork fan-out failed: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
