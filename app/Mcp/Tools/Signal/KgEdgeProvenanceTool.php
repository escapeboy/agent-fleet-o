<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Services\KnowledgeGraphTraversal;
use App\Domain\Memory\Models\Memory;
use App\Domain\Signal\Models\Entity;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool for retrieving source provenance of a knowledge graph entity.
 *
 * Given an entity name or UUID, returns the Memory records that contain that entity
 * via 'contains' edges (source_node_type='chunk', target_node_type='entity').
 * This reveals which memory chunks originally introduced a fact into the KG.
 */
#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class KgEdgeProvenanceTool extends Tool
{
    protected string $name = 'kg_edge_provenance';

    protected string $description = 'Return the source memory records that contain a given knowledge-graph entity (provenance tracing). Provide either entity_id (UUID) or entity_name to look up the source chunks that contributed facts about this entity.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_id' => $schema->string()
                ->description('UUID of the entity to trace (preferred when known)'),
            'entity_name' => $schema->string()
                ->description('Human-readable entity name to search for when entity_id is unavailable, e.g. "Alice Chen" or "Acme Corp"'),
            'entity_type' => $schema->string()
                ->description('Narrow entity_name lookup by type: person | company | location | product | topic')
                ->enum(['person', 'company', 'location', 'product', 'topic']),
            'limit' => $schema->integer()
                ->description('Max memory records to return (default: 20, max: 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'entity_id' => 'nullable|string|uuid',
            'entity_name' => 'nullable|string|max:255',
            'entity_type' => 'nullable|string|in:person,company,location,product,topic',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $teamId = app('mcp.team_id');
            $limit = min((int) ($validated['limit'] ?? 20), 100);

            // Resolve entity UUID
            $entityId = $validated['entity_id'] ?? null;

            if (empty($entityId)) {
                if (empty($validated['entity_name'])) {
                    return Response::text(json_encode([
                        'error' => 'Provide either entity_id or entity_name.',
                    ]));
                }

                $entity = $this->lookupEntityByName(
                    $teamId,
                    $validated['entity_name'],
                    $validated['entity_type'] ?? null,
                );

                if (! $entity) {
                    return Response::text(json_encode([
                        'entity_name' => $validated['entity_name'],
                        'found' => false,
                        'message' => 'No entity found with this name in the knowledge graph.',
                    ]));
                }

                $entityId = $entity->id;
            }

            $traversal = app(KnowledgeGraphTraversal::class);
            $memories = $traversal->sourceProvenance($teamId, $entityId)->take($limit);

            return Response::text(json_encode([
                'entity_id' => $entityId,
                'source_memory_count' => $memories->count(),
                'memories' => $memories->map(fn (Memory $m) => [
                    'id' => $m->id,
                    'content' => mb_substr($m->content, 0, 400),
                    'source_type' => $m->source_type,
                    'confidence' => $m->confidence,
                    'category' => $m->category->value ?? null,
                    'tags' => $m->tags ?? [],
                    'created' => $m->created_at?->toIso8601String(),
                ])->values()->toArray(),
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Resolve an Entity model by canonical name and optional type filter.
     */
    private function lookupEntityByName(string $teamId, string $name, ?string $type): ?Entity
    {
        $canonicalName = Str::lower(Str::ascii(trim($name)));

        $query = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('canonical_name', $canonicalName);

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->first();
    }
}
