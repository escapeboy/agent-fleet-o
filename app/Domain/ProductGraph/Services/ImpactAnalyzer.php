<?php

namespace App\Domain\ProductGraph\Services;

use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductNode;

/**
 * Blast-radius / impact analysis: "what is affected if this node changes?"
 *
 * Directed BFS over edges, where the traversal direction per edge type is given
 * by {@see EdgeType::impactDirection()}. Cycle-safe
 * (visited set), keeps the shallowest depth per affected node.
 */
class ImpactAnalyzer
{
    /**
     * @return list<array{node_id: string, name: ?string, node_type: ?string, depth: int, via_edge_type: string}>
     */
    public function blastRadius(ProductNode $node, ?int $maxDepth = null): array
    {
        $maxDepth = $maxDepth ?? (int) config('productgraph.max_impact_depth', 5);
        $teamId = $node->team_id;

        $visited = [$node->id => true];
        $queue = [[$node->id, 0]];
        $result = [];

        while ($queue !== []) {
            [$currentId, $depth] = array_shift($queue);

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($this->affectedNeighbors($teamId, $currentId) as $neighbor) {
                if (isset($visited[$neighbor['node_id']])) {
                    continue;
                }

                $visited[$neighbor['node_id']] = true;
                $result[] = [
                    'node_id' => $neighbor['node_id'],
                    'depth' => $depth + 1,
                    'via_edge_type' => $neighbor['via_edge_type'],
                ];
                $queue[] = [$neighbor['node_id'], $depth + 1];
            }
        }

        return $this->hydrateNames($teamId, $result);
    }

    /**
     * Neighbours affected when $nodeId changes.
     *
     * @return list<array{node_id: string, via_edge_type: string}>
     */
    private function affectedNeighbors(string $teamId, string $nodeId): array
    {
        $affected = [];

        $incoming = ProductEdge::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('target_node_id', $nodeId)
            ->get();

        foreach ($incoming as $edge) {
            if (in_array($edge->edge_type->impactDirection(), ['incoming', 'both'], true)) {
                $affected[] = ['node_id' => $edge->source_node_id, 'via_edge_type' => $edge->edge_type->value];
            }
        }

        $outgoing = ProductEdge::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('source_node_id', $nodeId)
            ->get();

        foreach ($outgoing as $edge) {
            if (in_array($edge->edge_type->impactDirection(), ['outgoing', 'both'], true)) {
                $affected[] = ['node_id' => $edge->target_node_id, 'via_edge_type' => $edge->edge_type->value];
            }
        }

        return $affected;
    }

    /**
     * @param  list<array{node_id: string, depth: int, via_edge_type: string}>  $rows
     * @return list<array{node_id: string, name: ?string, node_type: ?string, depth: int, via_edge_type: string}>
     */
    private function hydrateNames(string $teamId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $nodes = ProductNode::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('id', array_column($rows, 'node_id'))
            ->get()
            ->keyBy('id');

        return array_map(function (array $row) use ($nodes): array {
            $node = $nodes->get($row['node_id']);

            return [
                'node_id' => $row['node_id'],
                'name' => $node?->name,
                'node_type' => $node?->node_type->value,
                'depth' => $row['depth'],
                'via_edge_type' => $row['via_edge_type'],
            ];
        }, $rows);
    }
}
