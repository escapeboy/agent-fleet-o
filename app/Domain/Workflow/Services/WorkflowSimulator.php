<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\DTOs\SimulationResult;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Exceptions\UnstubbedNodeException;
use App\Domain\Workflow\Models\Workflow;

/**
 * Stub-based workflow simulator for unit testing workflow graphs.
 *
 * Usage:
 *   $result = (new WorkflowSimulator)
 *       ->stub($nodeId, ['score' => 0.9])
 *       ->run($workflow, ['input' => 'value']);
 *
 * Control-flow nodes (Conditional, Switch, DynamicFork, DoWhile) are handled
 * deterministically based on stub outputs. Execution-type nodes without a stub
 * throw UnstubbedNodeException.
 */
class WorkflowSimulator
{
    /** @param array<string, mixed> $stubs  Node ID => output array */
    public function __construct(
        private array $stubs = [],
        private int $maxSteps = 100,
    ) {}

    public function stub(string $nodeId, mixed $output): static
    {
        $clone = clone $this;
        $clone->stubs[$nodeId] = $output;

        return $clone;
    }

    public function run(Workflow $workflow, array $input = []): SimulationResult
    {
        $nodes = $workflow->nodes()->get()->keyBy('id');
        $edges = $workflow->edges()->get();

        // Build adjacency and reverse-adjacency maps
        $adjacency = [];    // source_id => [target_id, ...]
        $edgesBySource = []; // source_id => [edge, ...]

        foreach ($nodes as $nodeId => $node) {
            $adjacency[$nodeId] = [];
        }

        foreach ($edges as $edge) {
            $adjacency[$edge->source_node_id][] = $edge->target_node_id;
            $edgesBySource[$edge->source_node_id][] = $edge;
        }

        $startNode = $nodes->first(fn ($n) => $n->type === WorkflowNodeType::Start);

        if (! $startNode) {
            return new SimulationResult(
                executedPath: [],
                stepOutputs: [],
                terminationNodeId: '',
                terminationStatus: 'error',
            );
        }

        // Mutable working state
        $nodeOutputs = [$startNode->id => $input];
        $completed = [$startNode->id => true];
        $executedPath = [$startNode->id];
        $terminationNodeId = $startNode->id;
        $terminationStatus = 'completed';

        // Pruned targets — branch targets we won't visit (not-taken conditional paths)
        $skipped = [];

        $steps = 0;

        while ($steps++ < $this->maxSteps) {
            $executableNodes = $this->resolveExecutableNodes($nodes, $adjacency, $completed, $skipped);

            if (empty($executableNodes)) {
                break;
            }

            foreach ($executableNodes as $nodeId) {
                $node = $nodes[$nodeId];
                $nodeInput = $this->resolveNodeInput($nodeId, $adjacency, $nodeOutputs, $nodes);

                if ($node->type === WorkflowNodeType::End) {
                    $nodeOutputs[$nodeId] = $nodeInput;
                    $completed[$nodeId] = true;
                    $executedPath[] = $nodeId;
                    $terminationNodeId = $nodeId;
                    // Signal outer loop to stop
                    break 2;
                }

                if ($node->type->isControlFlow()) {
                    $output = $this->handleControlFlowNode(
                        $node, $nodeInput, $edgesBySource, $nodeOutputs, $adjacency, $skipped,
                    );
                    $nodeOutputs[$nodeId] = $output;
                    $completed[$nodeId] = true;
                    $executedPath[] = $nodeId;

                    continue;
                }

                // Execution node — must have a stub
                if (! array_key_exists($nodeId, $this->stubs)) {
                    throw new UnstubbedNodeException($nodeId, $node->type->value, $node->label ?? $nodeId);
                }

                $stubOutput = $this->stubs[$nodeId];
                $nodeOutputs[$nodeId] = is_array($stubOutput) ? $stubOutput : ['result' => $stubOutput];
                $completed[$nodeId] = true;
                $executedPath[] = $nodeId;
                $terminationNodeId = $nodeId;
            }
        }

        if ($steps > $this->maxSteps) {
            $terminationStatus = 'loop_limit';
        }

        return new SimulationResult(
            executedPath: $executedPath,
            stepOutputs: $nodeOutputs,
            terminationNodeId: $terminationNodeId,
            terminationStatus: $terminationStatus,
        );
    }

    /** @return array<string> Node IDs ready to execute */
    private function resolveExecutableNodes($nodeMap, array $adjacency, array $completed, array $skipped): array
    {
        $executable = [];

        foreach ($nodeMap as $nodeId => $node) {
            if (isset($completed[$nodeId]) || isset($skipped[$nodeId])) {
                continue;
            }

            // All predecessors must be completed (not just skipped)
            $allPredecessorsCompleted = true;
            foreach ($adjacency as $sourceId => $targets) {
                if (in_array($nodeId, $targets, true) && ! isset($completed[$sourceId])) {
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

    private function resolveNodeInput(string $nodeId, array $adjacency, array $nodeOutputs, $nodeMap): array
    {
        $predecessorOutputs = [];

        foreach ($adjacency as $sourceId => $targets) {
            if (in_array($nodeId, $targets, true) && isset($nodeOutputs[$sourceId])) {
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

        $merged = [];
        foreach ($predecessorOutputs as $sourceId => $output) {
            $label = $nodeMap[$sourceId]->label ?? $sourceId;
            $merged[$label] = $output;
        }

        return $merged;
    }

    /** @param array<string, bool> $skipped */
    private function handleControlFlowNode(
        $node,
        array $input,
        array $edgesBySource,
        array $nodeOutputs,
        array $adjacency,
        array &$skipped,
    ): array {
        return match ($node->type) {
            WorkflowNodeType::Start,
            WorkflowNodeType::Merge => $input,

            WorkflowNodeType::Conditional => $this->handleConditional(
                $node, $input, $edgesBySource, $nodeOutputs, $skipped,
            ),

            WorkflowNodeType::Switch => $this->handleSwitch(
                $node, $input, $edgesBySource, $nodeOutputs, $skipped,
            ),

            WorkflowNodeType::DynamicFork => $this->handleDynamicFork(
                $node, $input, $edgesBySource, $skipped,
            ),

            WorkflowNodeType::DoWhile => $input, // single-pass in simulation

            default => $input,
        };
    }

    /** @param array<string, bool> $skipped */
    private function handleConditional($node, array $input, array $edgesBySource, array $nodeOutputs, array &$skipped): array
    {
        $condition = $node->config['condition'] ?? null;
        $conditionEvaluator = app(ConditionEvaluator::class);

        // Find the predecessor whose output is the evaluation context
        $predecessorId = null;
        foreach ($nodeOutputs as $id => $_) {
            $predecessorId = $id;
        }

        $result = $conditionEvaluator->evaluate($condition, $nodeOutputs, $predecessorId);

        // Prune the non-taken branch
        $outgoing = $edgesBySource[$node->id] ?? [];

        if (count($outgoing) >= 2) {
            $trueBranchTarget = collect($outgoing)
                ->first(fn ($e) => ! ($e->is_default ?? false) && ! isset($e->case_value))
                ?->target_node_id;
            $falseBranchTarget = collect($outgoing)
                ->first(fn ($e) => ($e->is_default ?? false))
                ?->target_node_id;

            // Mark non-taken path as skipped so resolveExecutableNodes skips it
            if (! $result && $trueBranchTarget) {
                $this->pruneSubtree($trueBranchTarget, $node->id, $edgesBySource, $skipped);
            }
            if ($result && $falseBranchTarget) {
                $this->pruneSubtree($falseBranchTarget, $node->id, $edgesBySource, $skipped);
            }
        }

        return array_merge($input, ['_condition_result' => $result]);
    }

    /** @param array<string, bool> $skipped */
    private function handleSwitch($node, array $input, array $edgesBySource, array $nodeOutputs, array &$skipped): array
    {
        $expression = $node->config['expression'] ?? null;
        $value = (string) data_get($input, $expression ?? '', '');

        $outgoing = $edgesBySource[$node->id] ?? [];
        $defaultTarget = collect($outgoing)->first(fn ($e) => ($e->is_default ?? false))?->target_node_id;
        $matchedTarget = collect($outgoing)
            ->first(fn ($e) => ! ($e->is_default ?? false) && (string) ($e->case_value ?? '') === $value)
            ?->target_node_id;

        $takenTarget = $matchedTarget ?? $defaultTarget;

        foreach ($outgoing as $edge) {
            if ($edge->target_node_id !== $takenTarget) {
                $this->pruneSubtree($edge->target_node_id, $node->id, $edgesBySource, $skipped);
            }
        }

        return array_merge($input, ['_switch_value' => $value]);
    }

    /** @param array<string, bool> $skipped */
    private function handleDynamicFork($node, array $input, array $edgesBySource, array &$skipped): array
    {
        // In simulation: single-pass (inline mode only), no sub-workflow spawning
        $forkSource = $node->config['fork_source'] ?? null;
        $items = $forkSource ? (data_get($input, $forkSource) ?? []) : [];

        return array_merge($input, [
            '_fork_items' => is_array($items) ? $items : [$items],
            '_fork_variable' => $node->config['fork_variable_name'] ?? 'fork_item',
        ]);
    }

    /**
     * Recursively mark a subtree as skipped (for non-taken branches).
     * Stops at merge nodes or nodes that have predecessors outside the skipped set.
     *
     * @param  array<string, bool>  $skipped
     */
    private function pruneSubtree(string $nodeId, string $fromNodeId, array $edgesBySource, array &$skipped): void
    {
        if (isset($skipped[$nodeId])) {
            return;
        }

        $skipped[$nodeId] = true;

        foreach ($edgesBySource[$nodeId] ?? [] as $edge) {
            $this->pruneSubtree($edge->target_node_id, $nodeId, $edgesBySource, $skipped);
        }
    }
}
