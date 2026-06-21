<?php

namespace App\Domain\ProductGraph\Actions;

use App\Domain\ProductGraph\Enums\ChangeStatus;
use App\Domain\ProductGraph\Enums\ChangeType;
use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Enums\NodeStatus;
use App\Domain\ProductGraph\Enums\NodeType;
use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use App\Domain\ProductGraph\Models\ProductNode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Materialises an approved proposal into the graph. Idempotent: re-applying an
 * already-applied change is a no-op.
 */
class ApplyApprovedChangeAction
{
    public function __construct(
        private readonly CreateNodeAction $createNode,
        private readonly UpdateNodeAction $updateNode,
        private readonly DeleteNodeAction $deleteNode,
        private readonly UpsertEdgeAction $upsertEdge,
        private readonly DeleteEdgeAction $deleteEdge,
    ) {}

    public function execute(ProductGraphChange $change): ProductGraphChange
    {
        if ($change->status === ChangeStatus::Applied) {
            return $change;
        }

        if ($change->status !== ChangeStatus::Approved) {
            throw new RuntimeException('Only approved changes can be applied.');
        }

        return DB::transaction(function () use ($change) {
            $refId = match ($change->change_type) {
                ChangeType::CreateNode => $this->applyCreateNode($change),
                ChangeType::UpdateNode => $this->applyUpdateNode($change),
                ChangeType::DeleteNode => $this->applyDeleteNode($change),
                ChangeType::CreateEdge => $this->applyCreateEdge($change),
                ChangeType::DeleteEdge => $this->applyDeleteEdge($change),
            };

            $change->update([
                'status' => ChangeStatus::Applied->value,
                'applied_ref_id' => $refId,
            ]);

            return $change->refresh();
        });
    }

    private function applyCreateNode(ProductGraphChange $change): string
    {
        $p = $change->payload;

        $node = $this->createNode->execute(
            teamId: $change->team_id,
            type: NodeType::from((string) $p['node_type']),
            name: (string) $p['name'],
            status: NodeStatus::tryFrom((string) ($p['status'] ?? '')) ?? NodeStatus::Planned,
            description: $p['description'] ?? null,
            tags: $p['tags'] ?? [],
            externalRef: $p['external_ref'] ?? null,
            metadata: $p['metadata'] ?? [],
        );

        return $node->id;
    }

    private function applyUpdateNode(ProductGraphChange $change): string
    {
        $node = ProductNode::withoutGlobalScopes()
            ->where('team_id', $change->team_id)
            ->findOrFail($change->target_id);

        $this->updateNode->execute($node, $change->payload);

        return $node->id;
    }

    private function applyDeleteNode(ProductGraphChange $change): ?string
    {
        $node = ProductNode::withoutGlobalScopes()
            ->where('team_id', $change->team_id)
            ->find($change->target_id);

        if ($node !== null) {
            $this->deleteNode->execute($node);
        }

        return $change->target_id;
    }

    private function applyCreateEdge(ProductGraphChange $change): string
    {
        $p = $change->payload;

        $edge = $this->upsertEdge->execute(
            teamId: $change->team_id,
            sourceNodeId: (string) $p['source_node_id'],
            targetNodeId: (string) $p['target_node_id'],
            type: EdgeType::from((string) $p['edge_type']),
            description: $p['description'] ?? null,
        );

        return $edge->id;
    }

    private function applyDeleteEdge(ProductGraphChange $change): ?string
    {
        $edge = ProductEdge::withoutGlobalScopes()
            ->where('team_id', $change->team_id)
            ->find($change->target_id);

        if ($edge !== null) {
            $this->deleteEdge->execute($edge);
        }

        return $change->target_id;
    }
}
