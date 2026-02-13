<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\PlaybookExecutor;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Log;

class RetryFromStepAction
{
    public function __construct(
        private readonly TransitionExperimentAction $transitionAction,
    ) {}

    /**
     * Retry execution from a specific step, resetting it and its downstream dependents.
     */
    public function execute(Experiment $experiment, PlaybookStep $fromStep): void
    {
        Log::info('RetryFromStepAction: Retrying from step', [
            'experiment_id' => $experiment->id,
            'step_id' => $fromStep->id,
            'step_order' => $fromStep->order,
            'workflow_node_id' => $fromStep->workflow_node_id,
        ]);

        if ($experiment->hasWorkflow() && $fromStep->workflow_node_id) {
            $this->resetWorkflowDownstream($experiment, $fromStep);
        } else {
            $this->resetByOrder($experiment, $fromStep);
        }

        // Transition experiment back to Executing if it's in a failed state
        if ($experiment->status !== ExperimentStatus::Executing) {
            $this->transitionAction->execute(
                $experiment,
                ExperimentStatus::Executing,
                "Retrying from step #{$fromStep->order}",
            );
        } else {
            // Re-trigger the appropriate executor
            if ($experiment->hasWorkflow()) {
                app(WorkflowGraphExecutor::class)->execute($experiment);
            } else {
                app(PlaybookExecutor::class)->execute($experiment);
            }
        }
    }

    /**
     * For workflow experiments: reset only the target step and its graph-downstream successors.
     * This avoids resetting parallel siblings on unrelated branches.
     */
    private function resetWorkflowDownstream(Experiment $experiment, PlaybookStep $fromStep): void
    {
        $graph = $experiment->constraints['workflow_graph'] ?? null;

        if (! $graph) {
            $this->resetByOrder($experiment, $fromStep);

            return;
        }

        // Build adjacency map from graph edges
        $adjacency = [];
        foreach ($graph['edges'] as $edge) {
            $adjacency[$edge['source_node_id']][] = $edge['target_node_id'];
        }

        // Collect all downstream node IDs via BFS from the target node
        $downstreamNodeIds = $this->collectDownstreamNodeIds($fromStep->workflow_node_id, $adjacency);

        // Map node IDs to step IDs
        $stepsToReset = PlaybookStep::where('experiment_id', $experiment->id)
            ->whereIn('workflow_node_id', $downstreamNodeIds)
            ->pluck('id');

        Log::info('RetryFromStepAction: Resetting workflow downstream steps', [
            'experiment_id' => $experiment->id,
            'from_node_id' => $fromStep->workflow_node_id,
            'downstream_nodes' => count($downstreamNodeIds),
            'steps_to_reset' => $stepsToReset->count(),
        ]);

        PlaybookStep::where('experiment_id', $experiment->id)
            ->whereIn('id', $stepsToReset)
            ->update([
                'status' => 'pending',
                'output' => null,
                'error_message' => null,
                'duration_ms' => null,
                'cost_credits' => null,
                'started_at' => null,
                'completed_at' => null,
            ]);
    }

    /**
     * Collect the target node and all downstream node IDs via BFS.
     */
    private function collectDownstreamNodeIds(string $startNodeId, array $adjacency): array
    {
        $visited = [$startNodeId => true];
        $queue = [$startNodeId];

        while (! empty($queue)) {
            $current = array_shift($queue);

            foreach ($adjacency[$current] ?? [] as $successor) {
                if (! isset($visited[$successor])) {
                    $visited[$successor] = true;
                    $queue[] = $successor;
                }
            }
        }

        return array_keys($visited);
    }

    /**
     * Legacy fallback: reset by step order (for non-workflow playbooks).
     */
    private function resetByOrder(Experiment $experiment, PlaybookStep $fromStep): void
    {
        PlaybookStep::where('experiment_id', $experiment->id)
            ->where('order', '>=', $fromStep->order)
            ->update([
                'status' => 'pending',
                'output' => null,
                'error_message' => null,
                'duration_ms' => null,
                'cost_credits' => null,
                'started_at' => null,
                'completed_at' => null,
            ]);
    }
}
