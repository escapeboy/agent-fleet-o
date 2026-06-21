<?php

namespace App\Domain\ProductGraph\Actions;

use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductNode;
use InvalidArgumentException;

/**
 * Idempotent edge upsert. Guards self-loops and cross-tenant node references.
 */
class UpsertEdgeAction
{
    public function execute(
        string $teamId,
        string $sourceNodeId,
        string $targetNodeId,
        EdgeType $type,
        ?string $description = null,
    ): ProductEdge {
        if ($sourceNodeId === $targetNodeId) {
            throw new InvalidArgumentException('An edge cannot connect a node to itself.');
        }

        $belong = ProductNode::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereIn('id', [$sourceNodeId, $targetNodeId])
            ->count();

        if ($belong !== 2) {
            throw new InvalidArgumentException('Both nodes must exist and belong to the team.');
        }

        return ProductEdge::withoutGlobalScopes()->updateOrCreate(
            [
                'team_id' => $teamId,
                'source_node_id' => $sourceNodeId,
                'target_node_id' => $targetNodeId,
                'edge_type' => $type->value,
            ],
            ['description' => $description],
        );
    }
}
