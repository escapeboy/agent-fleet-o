<?php

namespace App\Domain\ProductGraph\Actions;

use App\Domain\ProductGraph\Enums\NodeStatus;
use App\Domain\ProductGraph\Enums\NodeType;
use App\Domain\ProductGraph\Models\ProductNode;
use Illuminate\Support\Str;

/**
 * Idempotent node upsert keyed on (team, type, slug) — safe for seeding/import.
 */
class CreateNodeAction
{
    /**
     * @param  string[]  $tags
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        string $teamId,
        NodeType $type,
        string $name,
        NodeStatus $status = NodeStatus::Planned,
        ?string $description = null,
        array $tags = [],
        ?string $externalRef = null,
        array $metadata = [],
    ): ProductNode {
        $slug = Str::slug($name);

        return ProductNode::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $teamId, 'node_type' => $type->value, 'slug' => $slug],
            [
                'name' => $name,
                'status' => $status->value,
                'description' => $description,
                'tags' => array_values(array_unique($tags)),
                'external_ref' => $externalRef,
                'metadata' => $metadata,
            ],
        );
    }
}
