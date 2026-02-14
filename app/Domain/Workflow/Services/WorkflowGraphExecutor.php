<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Crew\Jobs\ExecuteCrewWorkflowNodeJob;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\ProjectRun;
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
            [$state, $msg] = $this->resolveCompletionState($experiment, 'No workflow graph');
            $this->transitionAction->execute($experiment, $state, $msg);

            return;
        }

        $steps = PlaybookStep::where('experiment_id', $experiment->id)
            ->orderBy('order')
            ->get()
            ->keyBy('workflow_node_id');

        if ($steps->isEmpty()) {
            Log::warning('WorkflowGraphExecutor: No playbook steps', ['experiment_id' => $experiment->id]);
            [$state, $msg] = $this->resolveCompletionState($experiment, 'Workflow completed (no steps)');
            $this->transitionAction->execute($experiment, $state, $msg);

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

        // Resolve first set of executable nodes from Start.
        // Use allowLoopReset=false so completed steps are traversed through
        // (important for retry: we don't want to re-execute already-completed steps).
        $nextNodeIds = $adjacency[$startNode['id']] ?? [];
        $executableNodeIds = $this->resolveExecutableNodes(
            $nextNodeIds, $nodeMap, $edgeMap, $adjacency, $steps, $experiment, $maxLoopIterations,
            allowLoopReset: false,
        );

        // Filter to nodes whose ALL predecessors are complete (join semantics).
        // Without this, a retry on one branch could dispatch a downstream join node
        // before the other branch's retried step finishes.
        $executableNodeIds = $this->filterReadyNodes($executableNodeIds, $graph['edges'], $steps);

        if (empty($executableNodeIds)) {
            [$state, $msg] = $this->resolveCompletionState($experiment, 'Workflow completed');
            $this->transitionAction->execute($experiment, $state, $msg);

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
            $nextNodeIds, $nodeMap, $edgeMap, $adjacency, $steps, $experiment, $maxLoopIterations,
        );

        if (empty($executableNodeIds)) {
            // Check if all steps are done
            $pendingSteps = $steps->filter(fn (PlaybookStep $s) => $s->isPending() || $s->status === 'running');

            if ($pendingSteps->isEmpty()) {
                try {
                    [$state, $msg] = $this->resolveCompletionState($experiment, 'Workflow completed successfully');
                    $this->transitionAction->execute(
                        $experiment,
                        $state,
                        $msg,
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
     *
     * @param  bool  $allowLoopReset  When true, completed steps may be reset for loop re-execution.
     *                                When false, completed steps are traversed through to find pending ones.
     *                                Use false for initial/retry execution, true for back-edge loops.
     */
    private function resolveExecutableNodes(
        array $candidateNodeIds,
        array $nodeMap,
        array $edgeMap,
        array $adjacency,
        $steps,
        Experiment $experiment,
        int $maxLoopIterations,
        bool $allowLoopReset = true,
    ): array {
        $executable = [];
        $visited = [];

        foreach ($candidateNodeIds as $nodeId) {
            $this->resolveNode(
                $nodeId, $nodeMap, $edgeMap, $adjacency, $steps, $experiment,
                $maxLoopIterations, $executable, $visited, $allowLoopReset,
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
        bool $allowLoopReset = true,
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

            if ($step->isCompleted()) {
                if ($allowLoopReset && $step->loop_count < $maxLoopIterations) {
                    // Back-edge loop: reset for re-execution
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
                } elseif (! $allowLoopReset) {
                    // Initial/retry traversal: skip completed steps and continue to successors
                    foreach ($adjacency[$nodeId] ?? [] as $successor) {
                        $this->resolveNode(
                            $successor, $nodeMap, $edgeMap, $adjacency,
                            $steps, $experiment, $maxLoopIterations, $executable, $visited, $allowLoopReset,
                        );
                    }
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
     * Determine the completion state for a workflow experiment.
     *
     * One-shot projects complete directly after workflow finishes — they don't
     * need the CollectingMetrics -> Evaluating -> Iterating loop which is
     * designed for continuous/signal-driven experiments.
     *
     * @return array{ExperimentStatus, string} [status, reason]
     */
    private function resolveCompletionState(Experiment $experiment, string $reason): array
    {
        $projectRun = ProjectRun::where('experiment_id', $experiment->id)->first();
        $project = $projectRun?->project;

        if ($project && $project->type === ProjectType::OneShot) {
            return [ExperimentStatus::Completed, $reason.' (one-shot project)'];
        }

        return [ExperimentStatus::CollectingMetrics, $reason];
    }

    /**
     * Dispatch a batch of agent nodes for parallel execution.
     *
     * IMPORTANT: Batch callbacks are serialized into the database. They must NOT
     * capture `$this` (WorkflowGraphExecutor) because its injected dependencies
     * cannot be reliably deserialized by laravel/serializable-closure. Instead,
     * callbacks resolve a fresh instance from the container via static helpers.
     * Pass only scalar/array values (experiment ID, node IDs) — never Eloquent models.
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

        $experimentId = $experiment->id;

        Log::info('WorkflowGraphExecutor: Dispatching node batch', [
            'experiment_id' => $experimentId,
            'node_count' => count($jobs),
            'node_ids' => $dispatchedNodeIds,
        ]);

        Bus::batch($jobs)
            ->name("workflow:{$experimentId}:batch:".implode('-', array_slice($dispatchedNodeIds, 0, 3)))
            ->onQueue('experiments')
            ->allowFailures()
            ->then(function () use ($experimentId, $dispatchedNodeIds) {
                self::staticContinueAfterBatch($experimentId, $dispatchedNodeIds);
            })
            ->catch(function () use ($experimentId) {
                self::staticHandleBatchFailure($experimentId);
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

    /**
     * Static callback: continue execution after a batch completes.
     * Resolved from container to avoid serializing DI dependencies in closures.
     */
    private static function staticContinueAfterBatch(string $experimentId, array $completedNodeIds): void
    {
        try {
            $experiment = Experiment::withoutGlobalScopes()->find($experimentId);

            if (! $experiment || $experiment->status->isTerminal()) {
                return;
            }

            app(self::class)->continueAfterBatch($experiment, $completedNodeIds);
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: continueAfterBatch failed', [
                'experiment_id' => $experimentId,
                'error' => $e->getMessage(),
            ]);

            self::staticHandleBatchFailure($experimentId);
        }
    }

    /**
     * Static callback: handle batch failure by transitioning experiment.
     * Resolved from container to avoid serializing DI dependencies in closures.
     */
    private static function staticHandleBatchFailure(string $experimentId): void
    {
        try {
            $experiment = Experiment::withoutGlobalScopes()->find($experimentId);

            if (! $experiment || $experiment->status->isTerminal()) {
                return;
            }

            // Check if any step actually failed
            $failedSteps = PlaybookStep::where('experiment_id', $experimentId)
                ->where('status', 'failed')
                ->count();

            if ($failedSteps > 0) {
                app(TransitionExperimentAction::class)->execute(
                    $experiment,
                    ExperimentStatus::ExecutionFailed,
                    "Workflow step failed ({$failedSteps} failed step(s))",
                );
            }
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: handleBatchFailure failed', [
                'experiment_id' => $experimentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
