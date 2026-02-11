<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Crew\Jobs\ExecuteCrewWorkflowNodeJob;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class WorkflowGraphExecutor
{
    public function __construct(
        private readonly TransitionExperimentAction $transitionAction,
        private readonly ConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * Execute a workflow graph for an experiment.
     *
     * The graph snapshot is in experiment.constraints['workflow_graph'].
     * PlaybookStep rows have been materialized with workflow_node_id references.
     *
     * Execution strategy:
     * 1. Find the Start node from the graph snapshot
     * 2. Resolve its successors (agent/conditional/end nodes)
     * 3. For conditional nodes, evaluate conditions to pick the right path
     * 4. Group parallel successors into batches
     * 5. On batch completion, resolve next successors from completed nodes
     * 6. Repeat until all paths reach End nodes
     */
    public function execute(Experiment $experiment): void
    {
        $graph = $experiment->constraints['workflow_graph'] ?? null;

        if (! $graph) {
            Log::warning('WorkflowGraphExecutor: No workflow graph in experiment', [
                'experiment_id' => $experiment->id,
            ]);
            $this->transitionAction->execute($experiment, ExperimentStatus::CollectingMetrics, 'No workflow graph');

            return;
        }

        $steps = PlaybookStep::where('experiment_id', $experiment->id)
            ->orderBy('order')
            ->get()
            ->keyBy('workflow_node_id');

        if ($steps->isEmpty()) {
            Log::warning('WorkflowGraphExecutor: No playbook steps', ['experiment_id' => $experiment->id]);
            $this->transitionAction->execute($experiment, ExperimentStatus::CollectingMetrics, 'Workflow completed (no steps)');

            return;
        }

        // Find start node and resolve initial successors
        $startNode = collect($graph['nodes'])->firstWhere('type', 'start');

        if (! $startNode) {
            Log::error('WorkflowGraphExecutor: No start node in graph', ['experiment_id' => $experiment->id]);
            $this->transitionAction->execute($experiment, ExperimentStatus::ExecutionFailed, 'No start node in workflow');

            return;
        }

        // Build adjacency + edge maps from snapshot
        $adjacency = $this->buildAdjacencyMap($graph['edges']);
        $edgeMap = $this->buildEdgeMap($graph['edges']);
        $nodeMap = collect($graph['nodes'])->keyBy('id')->toArray();
        $maxLoopIterations = $graph['max_loop_iterations'] ?? 10;

        // Resolve first set of executable nodes from Start
        $nextNodeIds = $adjacency[$startNode['id']] ?? [];
        $executableNodeIds = $this->resolveExecutableNodes(
            $nextNodeIds, $nodeMap, $edgeMap, $adjacency, $steps, $experiment, $maxLoopIterations
        );

        if (empty($executableNodeIds)) {
            $this->transitionAction->execute($experiment, ExperimentStatus::CollectingMetrics, 'Workflow completed');

            return;
        }

        $this->dispatchNodeBatch($experiment, $executableNodeIds, $graph, $steps);
    }

    /**
     * Continue execution after a batch of nodes completes.
     * Called from the batch completion callback.
     */
    public function continueAfterBatch(Experiment $experiment, array $completedNodeIds): void
    {
        $experiment = Experiment::withoutGlobalScopes()->find($experiment->id);

        if (! $experiment || $experiment->status->isTerminal()) {
            return;
        }

        $graph = $experiment->constraints['workflow_graph'] ?? null;

        if (! $graph) {
            return;
        }

        $steps = PlaybookStep::where('experiment_id', $experiment->id)
            ->orderBy('order')
            ->get()
            ->keyBy('workflow_node_id');

        $adjacency = $this->buildAdjacencyMap($graph['edges']);
        $edgeMap = $this->buildEdgeMap($graph['edges']);
        $nodeMap = collect($graph['nodes'])->keyBy('id')->toArray();
        $maxLoopIterations = $graph['max_loop_iterations'] ?? 10;

        // Collect all successor node IDs from completed nodes
        $nextNodeIds = [];
        foreach ($completedNodeIds as $nodeId) {
            foreach ($adjacency[$nodeId] ?? [] as $successor) {
                $nextNodeIds[] = $successor;
            }
        }

        $nextNodeIds = array_unique($nextNodeIds);

        // Filter to nodes where ALL predecessors are complete
        $nextNodeIds = $this->filterReadyNodes($nextNodeIds, $graph['edges'], $steps);

        $executableNodeIds = $this->resolveExecutableNodes(
            $nextNodeIds, $nodeMap, $edgeMap, $adjacency, $steps, $experiment, $maxLoopIterations
        );

        if (empty($executableNodeIds)) {
            // Check if all steps are done
            $pendingSteps = $steps->filter(fn (PlaybookStep $s) => $s->isPending() || $s->status === 'running');

            if ($pendingSteps->isEmpty()) {
                try {
                    $this->transitionAction->execute(
                        $experiment,
                        ExperimentStatus::CollectingMetrics,
                        'Workflow completed successfully',
                    );
                } catch (\Throwable $e) {
                    Log::error('WorkflowGraphExecutor: Transition failed', [
                        'experiment_id' => $experiment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }

        $this->dispatchNodeBatch($experiment, $executableNodeIds, $graph, $steps);
    }

    /**
     * Resolve which nodes are actually executable from a set of candidate node IDs.
     * Traverses through conditional nodes to find agent/end nodes.
     */
    private function resolveExecutableNodes(
        array $candidateNodeIds,
        array $nodeMap,
        array $edgeMap,
        array $adjacency,
        $steps,
        Experiment $experiment,
        int $maxLoopIterations,
    ): array {
        $executable = [];
        $visited = [];

        foreach ($candidateNodeIds as $nodeId) {
            $this->resolveNode(
                $nodeId, $nodeMap, $edgeMap, $adjacency, $steps, $experiment,
                $maxLoopIterations, $executable, $visited,
            );
        }

        return array_unique($executable);
    }

    private function resolveNode(
        string $nodeId,
        array $nodeMap,
        array $edgeMap,
        array $adjacency,
        $steps,
        Experiment $experiment,
        int $maxLoopIterations,
        array &$executable,
        array &$visited,
    ): void {
        if (isset($visited[$nodeId])) {
            return;
        }
        $visited[$nodeId] = true;

        $node = $nodeMap[$nodeId] ?? null;

        if (! $node) {
            return;
        }

        $type = $node['type'];

        if ($type === 'end') {
            return;
        }

        if ($type === 'agent' || $type === 'crew') {
            $step = $steps[$nodeId] ?? null;

            if (! $step) {
                return;
            }

            // Check loop: if step already completed, check if we should loop
            if ($step->isCompleted()) {
                if ($step->loop_count < $maxLoopIterations) {
                    // Reset for re-execution
                    $step->update([
                        'status' => 'pending',
                        'output' => null,
                        'error_message' => null,
                        'duration_ms' => null,
                        'cost_credits' => null,
                        'started_at' => null,
                        'completed_at' => null,
                        'loop_count' => $step->loop_count + 1,
                    ]);
                    $executable[] = $nodeId;
                } else {
                    // Max iterations reached — skip this path
                    Log::info('WorkflowGraphExecutor: Max loop iterations reached', [
                        'experiment_id' => $experiment->id,
                        'node_id' => $nodeId,
                        'loop_count' => $step->loop_count,
                    ]);
                }

                return;
            }

            if ($step->isPending()) {
                $executable[] = $nodeId;
            }

            return;
        }

        if ($type === 'conditional') {
            // Evaluate conditions on outgoing edges to find next path
            $outgoingEdges = collect($edgeMap[$nodeId] ?? [])
                ->sortBy('sort_order');

            // Build context from completed steps
            $context = $this->buildNodeContext($steps, $experiment);

            // Find predecessors of this conditional node for default field resolution
            $predecessorNodeId = $this->findPredecessor($nodeId, $edgeMap);

            $matched = false;
            foreach ($outgoingEdges as $edge) {
                if ($edge['is_default'] ?? false) {
                    continue;
                }

                $condition = $edge['condition'] ?? null;

                if ($this->conditionEvaluator->evaluate($condition, $context, $predecessorNodeId)) {
                    $matched = true;
                    $this->resolveNode(
                        $edge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                        $steps, $experiment, $maxLoopIterations, $executable, $visited,
                    );

                    break;
                }
            }

            // No condition matched — use default edge
            if (! $matched) {
                $defaultEdge = $outgoingEdges->firstWhere('is_default', true);

                if ($defaultEdge) {
                    $this->resolveNode(
                        $defaultEdge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                        $steps, $experiment, $maxLoopIterations, $executable, $visited,
                    );
                }
            }

            return;
        }

        // Start node — just traverse to successors
        if ($type === 'start') {
            foreach ($adjacency[$nodeId] ?? [] as $successor) {
                $this->resolveNode(
                    $successor, $nodeMap, $edgeMap, $adjacency,
                    $steps, $experiment, $maxLoopIterations, $executable, $visited,
                );
            }
        }
    }

    /**
     * Filter candidate node IDs to only those whose ALL incoming edges are from completed nodes.
     * This enables proper join semantics for convergent paths.
     */
    private function filterReadyNodes(array $candidateNodeIds, array $edges, $steps): array
    {
        $ready = [];

        foreach ($candidateNodeIds as $nodeId) {
            $incomingEdges = collect($edges)->where('target_node_id', $nodeId);
            $allPredecessorsComplete = true;

            foreach ($incomingEdges as $edge) {
                $sourceStep = $steps[$edge['source_node_id']] ?? null;

                // If source is a non-agent node (start/conditional), it's always "complete"
                if (! $sourceStep) {
                    continue;
                }

                if (! $sourceStep->isCompleted() && ! $sourceStep->isSkipped()) {
                    $allPredecessorsComplete = false;

                    break;
                }
            }

            if ($allPredecessorsComplete) {
                $ready[] = $nodeId;
            }
        }

        return $ready;
    }

    /**
     * Dispatch a batch of agent nodes for parallel execution.
     */
    private function dispatchNodeBatch(Experiment $experiment, array $nodeIds, array $graph, $steps): void
    {
        $jobs = [];
        $dispatchedNodeIds = [];
        $nodeMap = collect($graph['nodes'])->keyBy('id')->toArray();

        foreach ($nodeIds as $nodeId) {
            $step = $steps[$nodeId] ?? null;

            if (! $step || (! $step->isPending() && $step->status !== 'running')) {
                continue;
            }

            $nodeType = $nodeMap[$nodeId]['type'] ?? 'agent';

            if ($nodeType === 'crew') {
                $jobs[] = new ExecuteCrewWorkflowNodeJob($step->id, $experiment->id, $experiment->team_id);
            } else {
                $jobs[] = new ExecutePlaybookStepJob($step->id, $experiment->id, $experiment->team_id);
            }

            $dispatchedNodeIds[] = $nodeId;
        }

        if (empty($jobs)) {
            return;
        }

        Log::info('WorkflowGraphExecutor: Dispatching node batch', [
            'experiment_id' => $experiment->id,
            'node_count' => count($jobs),
            'node_ids' => $dispatchedNodeIds,
        ]);

        Bus::batch($jobs)
            ->name("workflow:{$experiment->id}:batch:" . implode('-', array_slice($dispatchedNodeIds, 0, 3)))
            ->onQueue('experiments')
            ->allowFailures()
            ->then(function () use ($experiment, $dispatchedNodeIds) {
                app(self::class)->continueAfterBatch($experiment, $dispatchedNodeIds);
            })
            ->catch(function () use ($experiment) {
                $this->handleBatchFailure($experiment);
            })
            ->dispatch();
    }

    private function buildAdjacencyMap(array $edges): array
    {
        $map = [];

        foreach ($edges as $edge) {
            $map[$edge['source_node_id']][] = $edge['target_node_id'];
        }

        return $map;
    }

    private function buildEdgeMap(array $edges): array
    {
        $map = [];

        foreach ($edges as $edge) {
            $map[$edge['source_node_id']][] = $edge;
        }

        return $map;
    }

    /**
     * Build context map of node_id => output for condition evaluation.
     */
    private function buildNodeContext($steps, Experiment $experiment): array
    {
        $context = ['_experiment' => $experiment->toArray()];

        foreach ($steps as $nodeId => $step) {
            if ($step->isCompleted() && is_array($step->output)) {
                $context[$nodeId] = $step->output;
            }
        }

        return $context;
    }

    /**
     * Find the first predecessor node ID for a given node (for condition field resolution).
     */
    private function findPredecessor(string $nodeId, array $edgeMap): ?string
    {
        foreach ($edgeMap as $sourceId => $edges) {
            foreach ($edges as $edge) {
                if ($edge['target_node_id'] === $nodeId) {
                    return $sourceId;
                }
            }
        }

        return null;
    }

    private function handleBatchFailure(Experiment $experiment): void
    {
        try {
            $experiment = Experiment::withoutGlobalScopes()->find($experiment->id);

            if ($experiment && ! $experiment->status->isTerminal()) {
                // Check if any step actually failed
                $failedSteps = PlaybookStep::where('experiment_id', $experiment->id)
                    ->where('status', 'failed')
                    ->count();

                if ($failedSteps > 0) {
                    $this->transitionAction->execute(
                        $experiment,
                        ExperimentStatus::ExecutionFailed,
                        "Workflow step failed ({$failedSteps} failed step(s))",
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: Failed to handle batch failure', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
