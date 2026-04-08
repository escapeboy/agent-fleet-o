<?php

namespace App\Domain\Workflow\Services;

final class WorkflowGraphAnalyzer
{
    /**
     * Build a map of source_node_id => [target_node_id, ...] from a flat edge list.
     */
    public static function buildAdjacencyMap(array $edges): array
    {
        $map = [];

        foreach ($edges as $edge) {
            $map[$edge['source_node_id']][] = $edge['target_node_id'];
        }

        return $map;
    }

    /**
     * Build a map of source_node_id => [edge, ...] from a flat edge list.
     */
    public static function buildEdgeMap(array $edges): array
    {
        $map = [];

        foreach ($edges as $edge) {
            $map[$edge['source_node_id']][] = $edge;
        }

        return $map;
    }

    /**
     * Calculate priority scores for nodes based on unblocking potential.
     * Higher score = should execute first.
     *
     * Score = descendant_count * 2 + critical_path_depth + type_weight
     */
    public static function calculateNodePriorities(array $nodeIds, array $adjacency, array $nodeMap): array
    {
        $priorities = [];

        foreach ($nodeIds as $nodeId) {
            $descendants = self::countDescendants($nodeId, $adjacency);
            $depth = self::longestPathToEnd($nodeId, $adjacency, $nodeMap);

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
     * Longest path from node to any end node (critical path approximation).
     * Uses memoization and a visiting set to handle cycles safely.
     */
    public static function longestPathToEnd(string $nodeId, array $adjacency, array $nodeMap, array &$memo = [], array &$visiting = []): int
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
            $childDepth = self::longestPathToEnd($child, $adjacency, $nodeMap, $memo, $visiting);
            $max = max($max, $childDepth + 1);
        }

        unset($visiting[$nodeId]);

        return $memo[$nodeId] = $max;
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
    public static function filterReadyNodes(array $candidateNodeIds, array $edges, $steps, array $nodeMap = []): array
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
     * Count all transitive descendants of a node (BFS).
     */
    private static function countDescendants(string $nodeId, array $adjacency): int
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
}
