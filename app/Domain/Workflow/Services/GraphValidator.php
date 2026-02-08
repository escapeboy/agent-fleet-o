<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Collection;

class GraphValidator
{
    private Collection $nodes;
    private Collection $edges;
    private array $errors = [];

    public function validate(Workflow $workflow): array
    {
        $this->errors = [];
        $this->nodes = $workflow->nodes()->get();
        $this->edges = $workflow->edges()->get();

        $this->validateStartNode();
        $this->validateEndNodes();
        $this->validateOrphanNodes();
        $this->validateReachability();
        $this->validateConditionalNodes();
        $this->validateLoopExits();
        $this->validateAgentNodes();

        return $this->errors;
    }

    public function isValid(Workflow $workflow): bool
    {
        return empty($this->validate($workflow));
    }

    private function validateStartNode(): void
    {
        $startNodes = $this->nodes->where('type', WorkflowNodeType::Start);

        if ($startNodes->isEmpty()) {
            $this->errors[] = [
                'type' => 'missing_start',
                'message' => 'Workflow must have exactly one Start node.',
            ];
        } elseif ($startNodes->count() > 1) {
            $this->errors[] = [
                'type' => 'multiple_starts',
                'message' => 'Workflow must have exactly one Start node, found ' . $startNodes->count() . '.',
                'node_ids' => $startNodes->pluck('id')->toArray(),
            ];
        }
    }

    private function validateEndNodes(): void
    {
        $endNodes = $this->nodes->where('type', WorkflowNodeType::End);

        if ($endNodes->isEmpty()) {
            $this->errors[] = [
                'type' => 'missing_end',
                'message' => 'Workflow must have at least one End node.',
            ];
        }
    }

    private function validateOrphanNodes(): void
    {
        foreach ($this->nodes as $node) {
            if ($node->type === WorkflowNodeType::Start) {
                continue;
            }

            $hasIncoming = $this->edges->where('target_node_id', $node->id)->isNotEmpty();

            if (! $hasIncoming) {
                $this->errors[] = [
                    'type' => 'orphan_node',
                    'message' => "Node '{$node->label}' has no incoming edges and is unreachable.",
                    'node_id' => $node->id,
                ];
            }
        }

        foreach ($this->nodes as $node) {
            if ($node->type === WorkflowNodeType::End) {
                continue;
            }

            $hasOutgoing = $this->edges->where('source_node_id', $node->id)->isNotEmpty();

            if (! $hasOutgoing) {
                $this->errors[] = [
                    'type' => 'dead_end_node',
                    'message' => "Node '{$node->label}' has no outgoing edges.",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateReachability(): void
    {
        $startNode = $this->nodes->firstWhere('type', WorkflowNodeType::Start);

        if (! $startNode) {
            return;
        }

        $reachable = $this->findReachableNodes($startNode->id);
        $unreachable = $this->nodes->reject(fn (WorkflowNode $n) => $reachable->contains($n->id));

        foreach ($unreachable as $node) {
            $this->errors[] = [
                'type' => 'unreachable_node',
                'message' => "Node '{$node->label}' is not reachable from the Start node.",
                'node_id' => $node->id,
            ];
        }
    }

    private function findReachableNodes(string $fromNodeId): Collection
    {
        $visited = collect([$fromNodeId]);
        $queue = [$fromNodeId];

        while (! empty($queue)) {
            $currentId = array_shift($queue);

            $outgoing = $this->edges->where('source_node_id', $currentId);

            foreach ($outgoing as $edge) {
                if (! $visited->contains($edge->target_node_id)) {
                    $visited->push($edge->target_node_id);
                    $queue[] = $edge->target_node_id;
                }
            }
        }

        return $visited;
    }

    private function validateConditionalNodes(): void
    {
        $conditionalNodes = $this->nodes->where('type', WorkflowNodeType::Conditional);

        foreach ($conditionalNodes as $node) {
            $outgoing = $this->edges->where('source_node_id', $node->id);

            if ($outgoing->count() < 2) {
                $this->errors[] = [
                    'type' => 'conditional_insufficient_edges',
                    'message' => "Conditional node '{$node->label}' must have at least 2 outgoing edges.",
                    'node_id' => $node->id,
                ];
            }

            $hasDefault = $outgoing->where('is_default', true)->isNotEmpty();

            if (! $hasDefault && $outgoing->isNotEmpty()) {
                $this->errors[] = [
                    'type' => 'conditional_no_default',
                    'message' => "Conditional node '{$node->label}' must have a default (fallback) edge.",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateLoopExits(): void
    {
        $startNode = $this->nodes->firstWhere('type', WorkflowNodeType::Start);

        if (! $startNode) {
            return;
        }

        // Detect nodes that are part of cycles
        $cycleNodes = $this->detectCycleNodes();

        foreach ($cycleNodes as $nodeId) {
            $node = $this->nodes->firstWhere('id', $nodeId);

            if (! $node) {
                continue;
            }

            // Check that at least one outgoing edge from a cycle node leads outside the cycle
            $outgoing = $this->edges->where('source_node_id', $nodeId);
            $hasExit = false;

            foreach ($outgoing as $edge) {
                if (! in_array($edge->target_node_id, $cycleNodes)) {
                    $hasExit = true;
                    break;
                }
            }

            // Also check if incoming edges to this node from outside the cycle provide an exit
            // (the cycle can be exited via conditional logic on any node in the cycle)
            if (! $hasExit) {
                // Check if any node in the cycle has an exit
                foreach ($cycleNodes as $cycleNodeId) {
                    $cycleOutgoing = $this->edges->where('source_node_id', $cycleNodeId);
                    foreach ($cycleOutgoing as $edge) {
                        if (! in_array($edge->target_node_id, $cycleNodes)) {
                            $hasExit = true;
                            break 2;
                        }
                    }
                }
            }

            if (! $hasExit) {
                $this->errors[] = [
                    'type' => 'loop_no_exit',
                    'message' => "Loop containing node '{$node->label}' has no exit path. Add an edge leading outside the loop.",
                    'node_id' => $nodeId,
                ];
                break; // Only report once per cycle
            }
        }
    }

    private function detectCycleNodes(): array
    {
        $adjacency = [];
        foreach ($this->edges as $edge) {
            $adjacency[$edge->source_node_id][] = $edge->target_node_id;
        }

        $cycleNodes = [];
        $visited = [];
        $recursionStack = [];

        foreach ($this->nodes as $node) {
            if (! isset($visited[$node->id])) {
                $this->dfsDetectCycles($node->id, $adjacency, $visited, $recursionStack, $cycleNodes);
            }
        }

        return array_unique($cycleNodes);
    }

    private function dfsDetectCycles(
        string $nodeId,
        array $adjacency,
        array &$visited,
        array &$recursionStack,
        array &$cycleNodes,
    ): void {
        $visited[$nodeId] = true;
        $recursionStack[$nodeId] = true;

        foreach ($adjacency[$nodeId] ?? [] as $neighbor) {
            if (! isset($visited[$neighbor])) {
                $this->dfsDetectCycles($neighbor, $adjacency, $visited, $recursionStack, $cycleNodes);
            } elseif (isset($recursionStack[$neighbor])) {
                // Found a cycle — collect all nodes in the recursion stack
                $cycleNodes[] = $neighbor;
                $cycleNodes[] = $nodeId;
            }
        }

        unset($recursionStack[$nodeId]);
    }

    private function validateAgentNodes(): void
    {
        $agentNodes = $this->nodes->where('type', WorkflowNodeType::Agent);

        foreach ($agentNodes as $node) {
            if (! $node->agent_id) {
                $this->errors[] = [
                    'type' => 'agent_node_no_agent',
                    'message' => "Agent node '{$node->label}' has no agent assigned.",
                    'node_id' => $node->id,
                ];
            }
        }
    }
}
