<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Signal\Models\Signal;
use App\Domain\Workflow\Actions\DynamicForkFanOutAction;
use App\Domain\Workflow\Actions\ExecuteSubWorkflowAction;
use App\Domain\Workflow\Actions\RunCompensationChainAction;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Mcp\DeadlineContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkflowGraphExecutor
{
    public function __construct(
        private readonly TransitionExperimentAction $transitionAction,
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly WorkflowNodeDispatcher $nodeDispatcher,
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

        // Emit observability trace start if configured on the workflow
        $workflowModel = Workflow::withoutGlobalScopes()
            ->where('id', $graph['workflow_id'] ?? null)
            ->first();

        $traceId = $workflowModel ? $this->emitObservabilityTrace($workflowModel, 'start', [
            'experiment_id' => $experiment->id,
            'workflow_name' => $workflowModel->name,
        ]) : null;

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
        $adjacency = WorkflowGraphAnalyzer::buildAdjacencyMap($graph['edges']);
        $edgeMap = WorkflowGraphAnalyzer::buildEdgeMap($graph['edges']);
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
        $executableNodeIds = WorkflowGraphAnalyzer::filterReadyNodes($executableNodeIds, $graph['edges'], $steps, $nodeMap);

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

        $adjacency = WorkflowGraphAnalyzer::buildAdjacencyMap($graph['edges']);
        $edgeMap = WorkflowGraphAnalyzer::buildEdgeMap($graph['edges']);
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
        $nextNodeIds = WorkflowGraphAnalyzer::filterReadyNodes($nextNodeIds, $graph['edges'], $steps, $nodeMap);

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

        if ($type === 'annotation') {
            return;
        }

        if ($type === 'workflow_ref') {
            // Execute a referenced workflow inline and inject its output into the experiment context.
            $config = is_string($node['config'] ?? null) ? json_decode($node['config'], true) : ($node['config'] ?? []);
            $refWorkflowId = $config['ref_workflow_id'] ?? null;

            if (! $refWorkflowId) {
                Log::warning('WorkflowGraphExecutor: workflow_ref node missing ref_workflow_id', [
                    'node_id' => $nodeId,
                    'experiment_id' => $experiment->id,
                ]);

                return;
            }

            $refWorkflow = Workflow::withoutGlobalScopes()
                ->where('id', $refWorkflowId)
                ->where('team_id', $experiment->team_id)
                ->first();

            if (! $refWorkflow) {
                Log::warning('WorkflowGraphExecutor: workflow_ref target not found', [
                    'ref_workflow_id' => $refWorkflowId,
                    'experiment_id' => $experiment->id,
                ]);

                return;
            }

            // Resolve input from input_mapping (dot-notation into accumulated step outputs)
            $context = $this->buildNodeContext($steps, $experiment);
            $inputMapping = $config['input_mapping'] ?? [];
            $subInput = [];
            foreach ($inputMapping as $targetKey => $sourcePath) {
                $subInput[$targetKey] = data_get($context, $sourcePath);
            }

            try {
                $subOutput = app(ExecuteSubWorkflowAction::class)->execute($refWorkflow, $subInput, 0);
                $outputKey = $config['output_key'] ?? 'sub_workflow_output';

                // Store result so downstream nodes can reference it
                $step = $steps[$nodeId] ?? null;
                if ($step) {
                    $step->update([
                        'status' => 'completed',
                        'output' => [$outputKey => $subOutput],
                        'completed_at' => now(),
                    ]);
                    $executable[] = $nodeId;
                }
            } catch (\Throwable $e) {
                Log::error('WorkflowGraphExecutor: workflow_ref execution failed', [
                    'node_id' => $nodeId,
                    'ref_workflow_id' => $refWorkflowId,
                    'error' => $e->getMessage(),
                ]);
                $step = $steps[$nodeId] ?? null;
                if ($step) {
                    $step->update([
                        'status' => 'failed',
                        'error_message' => 'Sub-workflow failed: '.$e->getMessage(),
                        'completed_at' => now(),
                    ]);
                }
            }

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

        if ($type === 'iteration') {
            $config = is_string($node['config'] ?? null) ? json_decode($node['config'], true) : ($node['config'] ?? []);
            $sourceExpression = $config['source_expression'] ?? null;
            $itemVariable = $config['item_variable'] ?? 'item';
            $maxParallel = max(1, (int) ($config['max_parallel'] ?? 5));

            $context = $this->buildNodeContext($steps, $experiment);
            $items = $sourceExpression ? data_get($context, $sourceExpression) : null;

            $outgoingEdge = collect($edgeMap[$nodeId] ?? [])->first();

            if (! $outgoingEdge || ! is_array($items) || empty($items)) {
                if ($outgoingEdge) {
                    $this->resolveNode(
                        $outgoingEdge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                        $steps, $experiment, $maxLoopIterations, $executable, $visited,
                    );
                }

                return;
            }

            $templateNodeId = $outgoingEdge['target_node_id'];
            $templateStep = $steps[$templateNodeId] ?? null;

            if ($templateStep && $templateStep->isPending()) {
                $chunks = array_chunk(array_values($items), $maxParallel);
                $templateStep->update([
                    'input_mapping' => array_merge($templateStep->input_mapping ?? [], [
                        '_iteration_items' => array_values($items),
                        '_iteration_variable' => $itemVariable,
                        '_iteration_chunks' => $chunks,
                    ]),
                ]);
                $executable[] = $templateNodeId;
            }

            return;
        }

        if ($type === 'signal_route') {
            // Route based on a field in the experiment's signal metadata.
            // Config: { "attribute": "priority", "edges": [{"value": "critical", "case_value": "..."}] }
            $config = is_string($node['config'] ?? null) ? json_decode($node['config'], true) : ($node['config'] ?? []);
            $attribute = $config['attribute'] ?? null;

            // Resolve signal metadata from experiment context
            $signalData = [];
            if ($experiment->signal_id) {
                $signal = Signal::withoutGlobalScopes()
                    ->find($experiment->signal_id);
                $signalData = $signal->metadata ?? [];
            }

            $attributeValue = $attribute ? data_get($signalData, $attribute) : null;

            $outgoingEdges = collect($edgeMap[$nodeId] ?? [])->sortBy('sort_order');

            $matchedEdge = $outgoingEdges->first(function ($edge) use ($attributeValue) {
                return ! ($edge['is_default'] ?? false)
                    && isset($edge['case_value'])
                    && (string) $edge['case_value'] === (string) $attributeValue;
            });

            if ($matchedEdge) {
                $this->resolveNode(
                    $matchedEdge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                    $steps, $experiment, $maxLoopIterations, $executable, $visited,
                );

                return;
            }

            $defaultEdge = $outgoingEdges->firstWhere('is_default', true);
            if ($defaultEdge) {
                $this->resolveNode(
                    $defaultEdge['target_node_id'], $nodeMap, $edgeMap, $adjacency,
                    $steps, $experiment, $maxLoopIterations, $executable, $visited,
                );
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
        $this->nodeDispatcher->dispatchBatch($experiment, $nodeIds, $graph, $steps);
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
    public static function continueAfterBatchStatic(string $experimentId, array $completedNodeIds): void
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

            self::handleBatchFailureStatic($experimentId);
        }
    }

    /**
     * Static callback: handle batch failure by transitioning experiment.
     * Resolved from container to avoid serializing DI dependencies in closures.
     */
    public static function handleBatchFailureStatic(string $experimentId): void
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

                // Trigger saga compensation for nodes that declared a compensation_node_id
                app(RunCompensationChainAction::class)->execute($experiment->fresh() ?? $experiment);
            }
        } catch (\Throwable $e) {
            Log::error('WorkflowGraphExecutor: handleBatchFailure failed', [
                'experiment_id' => $experimentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Execute a workflow's graph inline (without creating an Experiment row).
     * Used by workflow_ref nodes to run sub-flows synchronously.
     *
     * Returns the accumulated step output keyed by node ID.
     *
     * @param  array<string, mixed>  $input
     * @param  int  $depth  Current recursion depth (enforced by ExecuteSubWorkflowAction).
     * @return array<string, mixed>
     */
    public function executeInline(Workflow $workflow, array $input, int $depth = 1): array
    {
        $nodes = $workflow->nodes()->orderBy('order')->get();
        $edges = $workflow->edges()->get();

        $nodeMap = $nodes->keyBy('id')->map(fn ($n) => [
            'id' => $n->id,
            'type' => $n->type instanceof WorkflowNodeType ? $n->type->value : $n->type,
            'config' => $n->config ?? [],
            'label' => $n->label,
            'agent_id' => $n->agent_id,
        ])->toArray();

        $edgeList = $edges->map(fn ($e) => [
            'source_node_id' => $e->source_node_id,
            'target_node_id' => $e->target_node_id,
            'condition' => $e->condition,
            'is_default' => $e->is_default,
            'sort_order' => $e->sort_order ?? 0,
        ])->values()->toArray();

        // Walk the graph depth-first and collect outputs from each executable node.
        // For inline execution we run synchronously through non-async node types only.
        $outputs = ['_input' => $input];

        $adjacency = WorkflowGraphAnalyzer::buildAdjacencyMap($edgeList);

        $startNode = collect($nodeMap)->firstWhere('type', 'start');
        if (! $startNode) {
            return $outputs;
        }

        // Propagate depth through workflow_ref node configs so nested calls enforce limits
        foreach ($nodeMap as &$node) {
            if (($node['type'] ?? '') === 'workflow_ref') {
                $node['config']['_depth'] = $depth;
            }
        }
        unset($node);

        $visited = [];
        $this->walkInline($startNode['id'], $nodeMap, $adjacency, $edgeList, $input, $outputs, $visited, $depth, $workflow->team_id);

        return $outputs;
    }

    /**
     * Recursive DFS walk for inline sub-workflow execution.
     *
     * @param  array<string, mixed>  $nodeMap
     * @param  array<string, array<int, string>>  $adjacency
     * @param  array<int, array<string, mixed>>  $edgeList
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $outputs
     */
    private function walkInline(
        string $nodeId,
        array $nodeMap,
        array $adjacency,
        array $edgeList,
        array $context,
        array &$outputs,
        array &$visited,
        int $depth,
        string $teamId = '',
    ): void {
        if (isset($visited[$nodeId])) {
            return;
        }
        $visited[$nodeId] = true;

        // Honor MCP-propagated deadline if present (synchronous inline execution path).
        app(DeadlineContext::class)->assertNotExpired();

        $node = $nodeMap[$nodeId] ?? null;
        if (! $node) {
            return;
        }

        $type = $node['type'];

        // Skip structural nodes
        if (in_array($type, ['start', 'end', 'annotation'], true)) {
            foreach ($adjacency[$nodeId] ?? [] as $next) {
                $this->walkInline($next, $nodeMap, $adjacency, $edgeList, $context, $outputs, $visited, $depth, $teamId);
            }

            return;
        }

        // Nested workflow_ref — enforce depth and delegate
        if ($type === 'workflow_ref') {
            $config = $node['config'] ?? [];
            $refWorkflowId = $config['ref_workflow_id'] ?? null;
            if ($refWorkflowId && $teamId) {
                $refWorkflow = Workflow::withoutGlobalScopes()
                    ->where('id', $refWorkflowId)
                    ->where('team_id', $teamId)
                    ->first();
                if ($refWorkflow) {
                    try {
                        $subOutput = app(ExecuteSubWorkflowAction::class)->execute($refWorkflow, $context, $depth);
                        $outputKey = $config['output_key'] ?? 'sub_workflow_output';
                        $outputs[$nodeId] = [$outputKey => $subOutput];
                    } catch (\Throwable $e) {
                        Log::warning('WorkflowGraphExecutor: inline workflow_ref failed', [
                            'ref_workflow_id' => $refWorkflowId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
            foreach ($adjacency[$nodeId] ?? [] as $next) {
                $this->walkInline($next, $nodeMap, $adjacency, $edgeList, $context, $outputs, $visited, $depth, $teamId);
            }

            return;
        }

        // For all other executable node types, record a placeholder output so
        // downstream mappings can resolve against it. Actual computation would
        // require the full Experiment pipeline; inline sub-workflows are intended
        // for lightweight template/transform/parameter_extractor chains.
        $outputs[$nodeId] = ['_node_type' => $type, '_skipped_async' => true];

        foreach ($adjacency[$nodeId] ?? [] as $next) {
            $this->walkInline($next, $nodeMap, $adjacency, $edgeList, $context, $outputs, $visited, $depth, $teamId);
        }
    }

    /**
     * Emit a fire-and-forget trace event to the workflow's configured observability provider.
     *
     * Supported providers: langfuse, langsmith.
     * Failures are swallowed — observability must never break workflow execution.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emitObservabilityTrace(Workflow $workflow, string $event, array $payload = []): ?string
    {
        $config = $workflow->observability_config ?? [];

        if (empty($config['enabled']) || empty($config['provider'])) {
            return null;
        }

        $provider = $config['provider'];
        $providerConfig = $config['config'] ?? [];
        $traceId = (string) Str::uuid();

        try {
            if ($provider === 'langfuse') {
                $host = rtrim($providerConfig['host'] ?? 'https://cloud.langfuse.com', '/');
                if (! $this->isSafeObservabilityUrl($host)) {
                    return null;
                }
                $publicKey = $providerConfig['public_key'] ?? '';
                $secretKey = $providerConfig['secret_key'] ?? '';

                Http::withBasicAuth($publicKey, $secretKey)
                    ->asJson()
                    ->timeout(3)
                    ->post("{$host}/api/public/ingestion", [
                        'batch' => [
                            [
                                'id' => (string) Str::uuid(),
                                'type' => 'trace-create',
                                'timestamp' => now()->toIso8601String(),
                                'body' => array_merge([
                                    'id' => $traceId,
                                    'name' => "workflow:{$workflow->name}:{$event}",
                                    'metadata' => $payload,
                                ], $event === 'end' ? ['output' => $payload] : ['input' => $payload]),
                            ],
                        ],
                    ]);
            } elseif ($provider === 'langsmith') {
                $host = rtrim($providerConfig['host'] ?? 'https://api.smith.langchain.com', '/');
                if (! $this->isSafeObservabilityUrl($host)) {
                    return null;
                }
                $apiKey = $providerConfig['api_key'] ?? '';

                Http::withHeaders(['x-api-key' => $apiKey])
                    ->asJson()
                    ->timeout(3)
                    ->post("{$host}/runs", [
                        'id' => $traceId,
                        'name' => "workflow:{$workflow->name}:{$event}",
                        'run_type' => 'chain',
                        'inputs' => $event === 'start' ? $payload : [],
                        'outputs' => $event === 'end' ? $payload : [],
                        'start_time' => now()->toIso8601String(),
                    ]);
            }
        } catch (\Throwable) {
            // Observability failures must never interrupt workflow execution.
        }

        return $traceId;
    }

    /**
     * Guard against SSRF: only allow HTTPS URLs pointing to public (non-RFC-1918) hosts.
     */
    private function isSafeObservabilityUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (($parsed['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = $parsed['host'] ?? '';
        if (! $host) {
            return false;
        }
        // Block loopback and link-local
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }
        // Resolve and block RFC-1918 / loopback / link-local ranges
        $ip = gethostbyname($host);
        if ($ip === $host && ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (['10.', '172.16.', '172.17.', '172.18.', '172.19.', '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.', '192.168.', '127.', '169.254.'] as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
