<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Approval\Actions\CreateHumanTaskAction;
use App\Domain\Crew\Jobs\ExecuteCrewWorkflowNodeJob;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Workflow\Actions\DispatchSubWorkflowAction;
use App\Domain\Workflow\Actions\DynamicForkFanOutAction;
use App\Domain\Workflow\Actions\HandleTimeGateAction;
use App\Domain\Workflow\Jobs\ExecuteWorkflowNodeJob;
use App\Domain\Workflow\Models\WorkflowNode;
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
        // Merge nodes use OR semantics (any predecessor complete is enough).
        $executableNodeIds = $this->filterReadyNodes($executableNodeIds, $graph['edges'], $steps, $nodeMap);

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

        // Collect successor node IDs, respecting output channels on edges.
        // If a step's output contains '_channel', only traverse edges whose
        // source_channel matches (or have no source_channel constraint).
        $nextNodeIds = [];
        foreach ($completedNodeIds as $nodeId) {
            $step = $steps[$nodeId] ?? null;
            $outputChannel = is_array($step?->output) ? ($step->output['_channel'] ?? null) : null;

            foreach ($edgeMap[$nodeId] ?? [] as $edge) {
                $edgeChannel = $edge['source_channel'] ?? null;
                if (empty($edgeChannel) || $edgeChannel === $outputChannel) {
                    $nextNodeIds[] = $edge['target_node_id'];
                }
            }
        }

        $nextNodeIds = array_unique($nextNodeIds);

        // Filter to nodes where ALL predecessors are complete (AND) or ANY (Merge/OR).
        $nextNodeIds = $this->filterReadyNodes($nextNodeIds, $graph['edges'], $steps, $nodeMap);

        $executableNodeIds = $this->resolveExecutableNodes(
            $nextNodeIds, $nodeMap, $edgeMap, $adjacency, $steps, $experiment, $maxLoopIterations,
        );

        if (empty($executableNodeIds)) {
            // Check if all steps are done
            $pendingSteps = $steps->filter(fn (PlaybookStep $s) => $s->isPending() || $s->status === 'running' || $s->isWaitingHuman() || $s->isWaitingTime());

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

        if ($type === 'agent' || $type === 'crew' || $type === 'human_task' || $type === 'time_gate' || $type === 'sub_workflow' || $type === 'boruna_step'
            || $type === 'llm' || $type === 'http_request' || $type === 'parameter_extractor'
            || $type === 'variable_aggregator' || $type === 'template_transform' || $type === 'knowledge_retrieval') {
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

        if ($type === 'switch') {
            $outgoingEdges = collect($edgeMap[$nodeId] ?? [])->sortBy('sort_order');
            $context = $this->buildNodeContext($steps, $experiment);
            $predecessorNodeId = $this->findPredecessor($nodeId, $edgeMap);

            $expressionField = $node['expression'] ?? null;

            if ($expressionField) {
                $switchValue = $this->conditionEvaluator->evaluateSwitch(
                    $expressionField, $context, $predecessorNodeId,
                );

                // Find edge with matching case_value
                $matchedEdge = $outgoingEdges->first(function ($edge) use ($switchValue) {
                    return ! ($edge['is_default'] ?? false) && ($edge['case_value'] ?? null) === $switchValue;
                });

                if ($matchedEdge) {
                    $this->resolveNode(
                        $matchedEdge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                        $steps, $experiment, $maxLoopIterations, $executable, $visited,
                    );

                    return;
                }
            }

            // Fallback to default edge
            $defaultEdge = $outgoingEdges->firstWhere('is_default', true);
            if ($defaultEdge) {
                $this->resolveNode(
                    $defaultEdge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                    $steps, $experiment, $maxLoopIterations, $executable, $visited,
                );
            }

            return;
        }

        if ($type === 'dynamic_fork') {
            // Resolve the array to fork over from predecessor output
            $config = is_string($node['config'] ?? null) ? json_decode($node['config'], true) : ($node['config'] ?? []);
            $forkSource = $config['fork_source'] ?? null;
            $context = $this->buildNodeContext($steps, $experiment);
            $predecessorNodeId = $this->findPredecessor($nodeId, $edgeMap);

            $arrayData = [];
            if ($forkSource && $predecessorNodeId && isset($context[$predecessorNodeId])) {
                $resolved = data_get($context[$predecessorNodeId], $forkSource);
                if (is_array($resolved)) {
                    $arrayData = $resolved;
                }
            }

            // Enforce max_parallel_branches limit
            $maxBranches = max(1, (int) ($config['max_parallel_branches'] ?? 50));
            $arrayData = array_slice($arrayData, 0, $maxBranches);

            $forkVariableName = $config['fork_variable_name'] ?? 'fork_item';
            $forkMode = $config['fork_execution_mode'] ?? 'inline';

            // Get the single outgoing edge (the template path)
            $outgoingEdge = collect($edgeMap[$nodeId] ?? [])->first();

            if (! $outgoingEdge || empty($arrayData)) {
                // Nothing to fork — traverse the template path once or skip
                if ($outgoingEdge) {
                    $this->resolveNode(
                        $outgoingEdge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                        $steps, $experiment, $maxLoopIterations, $executable, $visited,
                    );
                }

                return;
            }

            // The template path's target is the node to execute N times
            $templateNodeId = $outgoingEdge['target_node_id'];
            $templateStep = $steps[$templateNodeId] ?? null;

            if ($forkMode === 'sub_workflow' && $templateStep && $templateStep->isPending()) {
                // Fan-out: spawn one child experiment per array element
                app(DynamicForkFanOutAction::class)->execute(
                    step: $templateStep,
                    parent: $experiment,
                    forkItems: array_values($arrayData),
                    forkVariableName: $forkVariableName,
                    nodeData: $nodeMap[$templateNodeId] ?? [],
                );
            } elseif ($templateStep && $templateStep->isPending()) {
                // Inline (default): inject fork data into the template step for the executor to iterate
                $templateStep->update([
                    'input_mapping' => array_merge($templateStep->input_mapping ?? [], [
                        '_fork_items' => array_values($arrayData),
                        '_fork_source' => $forkSource,
                        '_fork_variable' => $forkVariableName,
                    ]),
                ]);
                $executable[] = $templateNodeId;
            }

            return;
        }

        if ($type === 'do_while') {
            $config = is_string($node['config'] ?? null) ? json_decode($node['config'], true) : ($node['config'] ?? []);
            $breakCondition = $config['break_condition'] ?? null;
            $context = $this->buildNodeContext($steps, $experiment);
            $predecessorNodeId = $this->findPredecessor($nodeId, $edgeMap);

            $shouldBreak = false;
            if ($breakCondition) {
                $shouldBreak = $this->conditionEvaluator->evaluateBreakCondition(
                    $breakCondition, $context, $predecessorNodeId,
                );
            }

            $outgoingEdges = collect($edgeMap[$nodeId] ?? [])->sortBy('sort_order');

            if ($shouldBreak) {
                // Take the default/exit edge
                $exitEdge = $outgoingEdges->firstWhere('is_default', true);
                if ($exitEdge) {
                    $this->resolveNode(
                        $exitEdge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                        $steps, $experiment, $maxLoopIterations, $executable, $visited,
                    );
                }
            } else {
                // Take the loop body edge (non-default)
                $loopEdge = $outgoingEdges->first(fn ($e) => ! ($e['is_default'] ?? false));
                if ($loopEdge) {
                    $this->resolveNode(
                        $loopEdge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                        $steps, $experiment, $maxLoopIterations, $executable, $visited,
                    );
                }
            }

            return;
        }

        // Merge node — OR fan-in: pass through to successors immediately.
        // filterReadyNodes handles the OR semantics (any predecessor complete).
        if ($type === 'merge') {
            foreach ($adjacency[$nodeId] ?? [] as $successor) {
                $this->resolveNode(
                    $successor, $nodeMap, $edgeMap, $adjacency,
                    $steps, $experiment, $maxLoopIterations, $executable, $visited,
                );
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
     * Filter candidate node IDs to only those that are ready to execute.
     *
     * Activation modes (per-node via activation_mode field):
     * - 'all' (default, AND): ALL incoming predecessors must be complete.
     * - 'any' (OR): ANY predecessor complete is sufficient.
     * - 'n_of_m' (threshold): At least N predecessors must be complete (N = activation_threshold).
     *
     * Merge nodes default to 'any' for backward compatibility.
     */
    private function filterReadyNodes(array $candidateNodeIds, array $edges, $steps, array $nodeMap = []): array
    {
        $ready = [];

        foreach ($candidateNodeIds as $nodeId) {
            $incomingEdges = collect($edges)->where('target_node_id', $nodeId);
            $node = $nodeMap[$nodeId] ?? [];
            $nodeType = $node['type'] ?? null;

            // Determine activation mode: explicit config > merge backward compat > default 'all'
            $activationMode = $node['activation_mode'] ?? null;
            if (! $activationMode) {
                $activationMode = ($nodeType === 'merge') ? 'any' : 'all';
            }

            $completedCount = 0;
            $totalCount = $incomingEdges->count();

            foreach ($incomingEdges as $edge) {
                $sourceStep = $steps[$edge['source_node_id']] ?? null;
                // Control-flow nodes have no step — always "complete"
                if (! $sourceStep || $sourceStep->isCompleted() || $sourceStep->isSkipped()) {
                    $completedCount++;
                }
            }

            $isReady = match ($activationMode) {
                'any' => $completedCount > 0,
                'n_of_m' => $completedCount >= max(1, (int) ($node['activation_threshold'] ?? $totalCount)),
                default => $completedCount >= $totalCount, // 'all' — AND semantics
            };

            if ($isReady) {
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
        $adjacency = $this->buildAdjacencyMap($graph['edges']);
        $nodeMap = collect($graph['nodes'])->keyBy('id')->toArray();

        // Sort nodes by priority (highest first) so the most impactful nodes execute first
        $priorities = $this->calculateNodePriorities($nodeIds, $adjacency, $nodeMap);
        arsort($priorities);
        $sortedNodeIds = array_keys($priorities);

        Log::info('WorkflowGraphExecutor: Node priorities', [
            'experiment_id' => $experiment->id,
            'priorities' => $priorities,
        ]);

        foreach ($sortedNodeIds as $nodeId) {
            $step = $steps[$nodeId] ?? null;

            if (! $step || (! $step->isPending() && $step->status !== 'running')) {
                continue;
            }

            $nodeType = $nodeMap[$nodeId]['type'] ?? 'agent';

            if ($nodeType === 'human_task') {
                // Human tasks create an approval request instead of dispatching a job
                $this->dispatchHumanTask($step, $experiment, $nodeMap[$nodeId]);
                $dispatchedNodeIds[] = $nodeId;

                continue;
            }

            if ($nodeType === 'time_gate') {
                // Time gates mark the step as waiting_time and schedule a delayed wakeup
                $this->dispatchTimeGate($step, $experiment, $nodeMap[$nodeId]);
                $dispatchedNodeIds[] = $nodeId;

                continue;
            }

            if ($nodeType === 'sub_workflow') {
                // Sub-workflow nodes spawn a child experiment and keep the step in "running"
                $this->dispatchSubWorkflow($step, $experiment, $nodeMap[$nodeId]);
                $dispatchedNodeIds[] = $nodeId;

                continue;
            }

            if ($nodeType === 'crew') {
                $jobs[] = new ExecuteCrewWorkflowNodeJob($step->id, $experiment->id, $experiment->team_id);
            } elseif (in_array($nodeType, ['llm', 'http_request', 'parameter_extractor', 'variable_aggregator', 'template_transform', 'knowledge_retrieval'], true)) {
                // Lightweight node types use the dedicated ExecuteWorkflowNodeJob
                $jobs[] = new ExecuteWorkflowNodeJob($step->id, $experiment->id, $experiment->team_id);
            } else {
                // agent, boruna_step, and any future execution node types all use ExecutePlaybookStepJob
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

    private function dispatchHumanTask(PlaybookStep $step, Experiment $experiment, array $nodeData): void
    {
        $workflowNode = WorkflowNode::find($step->workflow_node_id);

        if (! $workflowNode) {
            Log::warning('WorkflowGraphExecutor: Human task node not found', [
                'step_id' => $step->id,
                'workflow_node_id' => $step->workflow_node_id,
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => 'Workflow node not found for human task',
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            app(CreateHumanTaskAction::class)->execute($experiment, $step, $workflowNode);

            Log::info('WorkflowGraphExecutor: Human task created', [
                'step_id' => $step->id,
                'experiment_id' => $experiment->id,
                'node_label' => $nodeData['label'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: Failed to create human task', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => 'Failed to create human task: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    private function dispatchTimeGate(PlaybookStep $step, Experiment $experiment, array $nodeData): void
    {
        try {
            app(HandleTimeGateAction::class)->execute($step, $experiment, $nodeData);
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: Failed to activate time gate', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => 'Failed to activate time gate: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    private function dispatchSubWorkflow(PlaybookStep $step, Experiment $experiment, array $nodeData): void
    {
        try {
            app(DispatchSubWorkflowAction::class)->execute($step, $experiment, $nodeData);
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: Failed to dispatch sub-workflow', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);
            $step->update([
                'status' => 'failed',
                'error_message' => 'Failed to dispatch sub-workflow: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * Calculate priority scores for nodes based on unblocking potential.
     * Higher score = should execute first.
     *
     * Score = descendant_count * 2 + critical_path_depth + type_weight
     */
    private function calculateNodePriorities(array $nodeIds, array $adjacency, array $nodeMap): array
    {
        $priorities = [];

        foreach ($nodeIds as $nodeId) {
            $descendants = $this->countDescendants($nodeId, $adjacency);
            $depth = $this->longestPathToEnd($nodeId, $adjacency, $nodeMap);

            // Human tasks take longer — prioritize unblocking them first
            $typeWeight = match ($nodeMap[$nodeId]['type'] ?? 'agent') {
                'human_task' => 3,
                'sub_workflow' => 3,
                'crew' => 2,
                'time_gate' => 2,
                'agent' => 1,
                'boruna_step' => 1,
                'llm' => 1,
                'http_request' => 1,
                'parameter_extractor' => 1,
                'knowledge_retrieval' => 1,
                'variable_aggregator' => 0,
                'template_transform' => 0,
                default => 0,
            };

            $priorities[$nodeId] = ($descendants * 2) + $depth + $typeWeight;
        }

        return $priorities;
    }

    /**
     * Count all transitive descendants of a node (BFS).
     */
    private function countDescendants(string $nodeId, array $adjacency): int
    {
        $visited = [];
        $queue = $adjacency[$nodeId] ?? [];
        $count = 0;

        while (! empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $count++;
            foreach ($adjacency[$current] ?? [] as $child) {
                if (! isset($visited[$child])) {
                    $queue[] = $child;
                }
            }
        }

        return $count;
    }

    /**
     * Longest path from node to any end node (critical path approximation).
     * Uses memoization and a visiting set to handle cycles safely.
     */
    private function longestPathToEnd(string $nodeId, array $adjacency, array $nodeMap, array &$memo = [], array &$visiting = []): int
    {
        if (isset($memo[$nodeId])) {
            return $memo[$nodeId];
        }

        // Cycle detection: if we're already visiting this node, return 0
        if (isset($visiting[$nodeId])) {
            return 0;
        }

        if (($nodeMap[$nodeId]['type'] ?? '') === 'end') {
            return $memo[$nodeId] = 0;
        }

        $visiting[$nodeId] = true;

        $max = 0;
        foreach ($adjacency[$nodeId] ?? [] as $child) {
            $childDepth = $this->longestPathToEnd($child, $adjacency, $nodeMap, $memo, $visiting);
            $max = max($max, $childDepth + 1);
        }

        unset($visiting[$nodeId]);

        return $memo[$nodeId] = $max;
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
            if ($step && $step->isCompleted() && is_array($step->output)) {
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
