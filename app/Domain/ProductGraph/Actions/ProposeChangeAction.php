<?php

namespace App\Domain\ProductGraph\Actions;

use App\Domain\ProductGraph\Enums\ChangeStatus;
use App\Domain\ProductGraph\Enums\ChangeType;
use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Enums\NodeType;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use InvalidArgumentException;

/**
 * Records a proposed graph mutation in the pending review queue WITHOUT touching
 * the graph. "Agents propose. Humans decide." The change is applied only after a
 * human approves it via {@see ReviewChangeAction}.
 */
class ProposeChangeAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(
        string $teamId,
        ChangeType $type,
        ?string $targetId,
        array $payload,
        string $proposedByLabel = 'user',
        ?string $proposedByUserId = null,
    ): ProductGraphChange {
        $this->validate($type, $targetId, $payload);

        return ProductGraphChange::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'change_type' => $type->value,
            'target_id' => $targetId,
            'payload' => $payload,
            'status' => ChangeStatus::Pending->value,
            'proposed_by_label' => $proposedByLabel,
            'proposed_by_user_id' => $proposedByUserId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validate(ChangeType $type, ?string $targetId, array $payload): void
    {
        switch ($type) {
            case ChangeType::CreateNode:
                $this->require($payload, ['node_type', 'name']);
                if (NodeType::tryFrom((string) $payload['node_type']) === null) {
                    throw new InvalidArgumentException('Invalid node_type in payload.');
                }
                break;

            case ChangeType::UpdateNode:
            case ChangeType::DeleteNode:
            case ChangeType::DeleteEdge:
                if ($targetId === null || $targetId === '') {
                    throw new InvalidArgumentException($type->value.' requires a target_id.');
                }
                break;

            case ChangeType::CreateEdge:
                $this->require($payload, ['source_node_id', 'target_node_id', 'edge_type']);
                if (EdgeType::tryFrom((string) $payload['edge_type']) === null) {
                    throw new InvalidArgumentException('Invalid edge_type in payload.');
                }
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  string[]  $keys
     */
    private function require(array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            if (! isset($payload[$key]) || $payload[$key] === '') {
                throw new InvalidArgumentException("Missing required payload field: {$key}.");
            }
        }
    }
}
