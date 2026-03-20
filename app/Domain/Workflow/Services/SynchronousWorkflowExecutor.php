<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Workflow\Actions\ExecuteWorkflowStepAction;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Facades\Log;

class SynchronousWorkflowExecutor
{
    public function __construct(
        private readonly ExecuteWorkflowStepAction $executeStep,
        private readonly ConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * Execute a workflow in-process (no queue dispatch).
     * Returns the final output string for use as a tool result.
     */
    public function execute(
        Workflow $workflow,
        string $teamId,
        string $userId,
        array $input,
        int $currentDepth = 0,
        int $timeoutSeconds = 120,
        ?string $parentExperimentId = null,
    ): string {
        $maxDepth = min(
            (int) config('workflows.max_recursion_depth', 5),
            (int) config('agent.max_agent_tool_depth', 3),
        );

        if ($currentDepth >= $maxDepth) {
            return "Error: Maximum workflow nesting depth ({$maxDepth}) exceeded.";
        }

        // Snapshot graph
        $nodes = $workflow->nodes()->with('agent', 'crew')->get();
        $edges = $workflow->edges()->get();

        if ($nodes->isEmpty()) {
            return 'Error: Workflow has no nodes.';
        }

        // Reject workflows containing human_task nodes (async-only)
        $hasHumanTask = $nodes->contains(fn ($n) => $n->type === WorkflowNodeType::HumanTask);
        if ($hasHumanTask) {
            return 'Error: Workflow contains human_task nodes which cannot be executed synchronously.';
        }

        // Build graph structures
        $nodeMap = $nodes->keyBy('id');
        $adjacency = [];  // source_id => [target_ids]
        $inDegree = [];   // node_id => count of incoming edges
        $edgesBySource = []; // source_id => [edges]

        foreach ($nodes as $node) {
            $adjacency[$node->id] = [];
            $inDegree[$node->id] = 0;
        }

        foreach ($edges as $edge) {
            $adjacency[$edge->source_node_id][] = $edge->target_node_id;
            $inDegree[$edge->target_node_id] = ($inDegree[$edge->target_node_id] ?? 0) + 1;
            $edgesBySource[$edge->source_node_id][] = $edge;
        }

        $startNode = $nodes->firstWhere('type', WorkflowNodeType::Start);
        if (! $startNode) {
            return 'Error: No start node found in workflow.';
        }

        // Create ephemeral experiment for audit trail
        $experiment = $this->createEphemeralExperiment($workflow, $teamId, $userId, $input, $parentExperimentId);

        $nodeOutputs = [];
        $nodeOutputs[$startNode->id] = $input;
        $completed = [$startNode->id => true];

        $deadline = now()->addSeconds($timeoutSeconds);
        $maxIterations = $workflow->max_loop_iterations ?? 100;
        $iteration = 0;

        try {
            while ($iteration++ < $maxIterations && now()->lt($deadline)) {
                $executableNodes = $this->resolveExecutableNodes($adjacency, $inDegree, $completed, $nodeMap);

                if (empty($executableNodes)) {
                    break;
                }

                foreach ($executableNodes as $nodeId) {
                    if (now()->gt($deadline)) {
                        break 2;
                    }

                    $node = $nodeMap[$nodeId];
                    $nodeInput = $this->resolveNodeInput($node, $adjacency, $nodeOutputs, $edgesBySource, $nodeMap);

                    // Control flow nodes
                    if ($node->type->isControlFlow()) {
                        $nodeOutputs[$nodeId] = $this->handleControlFlowNode($node, $nodeInput, $edgesBySource, $nodeOutputs, $adjacency, $completed, $nodeMap);
                        $completed[$nodeId] = true;

                        continue;
                    }

                    // Execution nodes (agent, crew, etc.)
                    $result = $this->executeStep->execute(
                        node: $this->nodeToArray($node),
                        input: $nodeInput,
                        teamId: $teamId,
                        userId: $userId,
                        experimentId: $experiment->id,
                        depth: $currentDepth,
                    );

                    $nodeOutputs[$nodeId] = $result;
                    $completed[$nodeId] = true;
                }
            }

            // Collect output from End node predecessors
            $finalOutput = $this->collectFinalOutput($nodeMap, $adjacency, $nodeOutputs, $completed);

            $experiment->update([
                'status' => ExperimentStatus::Completed,
                'meta' => ['final_output' => $finalOutput, 'is_ephemeral' => true],
            ]);

            return $finalOutput ?: 'Workflow completed without output.';
        } catch (\Throwable $e) {
            Log::warning('SynchronousWorkflowExecutor failed', [
                'workflow_id' => $workflow->id,
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            $experiment->update([
                'status' => ExperimentStatus::Killed,
                'meta' => ['error' => $e->getMessage(), 'is_ephemeral' => true],
            ]);

            return "Workflow execution failed: {$e->getMessage()}";
        }
    }

    /**
     * Resolve nodes whose all predecessors have completed.
     *
     * @return array<string> Node IDs ready to execute
     */
    private function resolveExecutableNodes(array $adjacency, array $inDegree, array $completed, $nodeMap): array
    {
        $executable = [];

        foreach ($nodeMap as $nodeId => $node) {
            if (isset($completed[$nodeId])) {
                continue;
            }

            // Check if all predecessors are completed
            $allPredecessorsCompleted = true;
            foreach ($adjacency as $sourceId => $targets) {
                if (in_array($nodeId, $targets) && ! isset($completed[$sourceId])) {
                    $allPredecessorsCompleted = false;
                    break;
                }
            }

            if ($allPredecessorsCompleted) {
                $executable[] = $nodeId;
            }
        }

        return $executable;
    }

    /**
     * Resolve input for a node by merging outputs from predecessors.
     */
    private function resolveNodeInput($node, array $adjacency, array $nodeOutputs, array $edgesBySource, $nodeMap): array
    {
        $predecessorOutputs = [];

        foreach ($adjacency as $sourceId => $targets) {
            if (in_array($node->id, $targets) && isset($nodeOutputs[$sourceId])) {
                $predecessorOutputs[$sourceId] = $nodeOutputs[$sourceId];
            }
        }

        if (empty($predecessorOutputs)) {
            return [];
        }

        if (count($predecessorOutputs) === 1) {
            $output = reset($predecessorOutputs);

            return is_array($output) ? $output : ['result' => $output];
        }

        // Merge multiple predecessor outputs
        $merged = [];
        foreach ($predecessorOutputs as $sourceId => $output) {
            $sourceName = $nodeMap[$sourceId]->label ?? $sourceId;
            $merged[$sourceName] = $output;
        }

        return $merged;
    }

    /**
     * Handle control-flow nodes (conditional, switch, merge, etc.).
     */
    private function handleControlFlowNode($node, array $input, array $edgesBySource, array $nodeOutputs, array &$adjacency, array &$completed, $nodeMap): array
    {
        return match ($node->type) {
            WorkflowNodeType::Start => $input,
            WorkflowNodeType::End => $input,
            WorkflowNodeType::Merge => $input,
            WorkflowNodeType::Conditional => $this->handleConditionalNode($node, $input, $edgesBySource, $nodeOutputs),
            WorkflowNodeType::Switch => $this->handleSwitchNode($node, $input, $edgesBySource, $nodeOutputs),
            WorkflowNodeType::DynamicFork => $input, // Simplified: pass through for now
            WorkflowNodeType::DoWhile => $input, // Simplified: single pass
            default => $input,
        };
    }

    private function handleConditionalNode($node, array $input, array $edgesBySource, array $nodeOutputs): array
    {
        $condition = $node->config['condition'] ?? null;

        // Get predecessor node ID for context
        $predecessorId = null;
        foreach ($nodeOutputs as $id => $output) {
            $predecessorId = $id;
        }

        $result = $this->conditionEvaluator->evaluate($condition, $nodeOutputs, $predecessorId);

        return array_merge($input, ['_condition_result' => $result]);
    }

    private function handleSwitchNode($node, array $input, array $edgesBySource, array $nodeOutputs): array
    {
        $expression = $node->config['expression'] ?? null;
        $value = data_get($input, $expression, '');

        return array_merge($input, ['_switch_value' => $value]);
    }

    private function createEphemeralExperiment(Workflow $workflow, string $teamId, string $userId, array $input, ?string $parentId): Experiment
    {
        return Experiment::create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'workflow_id' => $workflow->id,
            'parent_experiment_id' => $parentId,
            'title' => "Workflow Tool: {$workflow->name}",
            'thesis' => 'Ephemeral workflow-as-tool execution',
            'track' => ExperimentTrack::Workflow,
            'status' => ExperimentStatus::Executing,
            'constraints' => [
                'is_ephemeral' => true,
                'input' => $input,
            ],
        ]);
    }

    private function collectFinalOutput($nodeMap, array $adjacency, array $nodeOutputs, array $completed): string
    {
        // Find End node
        $endNode = $nodeMap->first(fn ($n) => $n->type === WorkflowNodeType::End);

        if (! $endNode) {
            // Collect from all completed execution nodes
            $outputs = [];
            foreach ($nodeOutputs as $nodeId => $output) {
                $node = $nodeMap[$nodeId] ?? null;
                if ($node && ! $node->type->isControlFlow()) {
                    $outputs[] = $output['result'] ?? json_encode($output);
                }
            }

            return implode("\n\n", $outputs);
        }

        // Collect from End node's predecessors
        $predecessorOutputs = [];
        foreach ($adjacency as $sourceId => $targets) {
            if (in_array($endNode->id, $targets) && isset($nodeOutputs[$sourceId])) {
                $output = $nodeOutputs[$sourceId];
                $predecessorOutputs[] = $output['result'] ?? json_encode($output);
            }
        }

        return implode("\n\n", $predecessorOutputs);
    }

    private function nodeToArray($node): array
    {
        return [
            'id' => $node->id,
            'type' => $node->type->value,
            'label' => $node->label,
            'agent_id' => $node->agent_id,
            'crew_id' => $node->crew_id,
            'config' => $node->config ?? [],
        ];
    }
}
