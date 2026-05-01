<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Actions\MergeEntitiesAction;
use App\Domain\Signal\Models\Entity;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class KgMergeEntitiesTool extends Tool
{
    protected string $name = 'kg_merge_entities';

    protected string $description = 'Merges a duplicate entity into a canonical entity, redirecting all edges and signal associations. The duplicate entity is deleted. Use kg_suggest_merges first to identify candidates.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'canonical_entity_id' => $schema->string()
                ->description('UUID of the entity to keep as canonical')
                ->required(),
            'duplicate_entity_id' => $schema->string()
                ->description('UUID of the duplicate entity to merge and delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'canonical_entity_id' => 'required|string|uuid',
            'duplicate_entity_id' => 'required|string|uuid',
        ]);

        $teamId = app('mcp.team_id');
        $canonicalId = $validated['canonical_entity_id'];
        $duplicateId = $validated['duplicate_entity_id'];

        if ($canonicalId === $duplicateId) {
            return Response::text(json_encode([
                'success' => false,
                'error' => 'canonical_entity_id and duplicate_entity_id must be different',
            ]));
        }

        $canonical = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $canonicalId)
            ->first();

        if (! $canonical) {
            return Response::text(json_encode([
                'success' => false,
                'error' => 'canonical_entity_id not found in this team',
            ]));
        }

        $duplicate = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $duplicateId)
            ->first();

        if (! $duplicate) {
            return Response::text(json_encode([
                'success' => false,
                'error' => 'duplicate_entity_id not found in this team',
            ]));
        }

        /** @var MergeEntitiesAction $action */
        $action = app(MergeEntitiesAction::class);
        $action->execute($teamId, $canonicalId, $duplicateId);

        return Response::text(json_encode([
            'success' => true,
            'canonical_id' => $canonicalId,
            'canonical_name' => $canonical->name,
            'merged_name' => $duplicate->name,
            'message' => "Merged \"{$duplicate->name}\" into \"{$canonical->name}\"",
        ]));
    }
}
