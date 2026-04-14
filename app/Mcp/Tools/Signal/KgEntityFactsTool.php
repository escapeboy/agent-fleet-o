<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\KnowledgeGraph\Services\TemporalKnowledgeGraphService;
use App\Domain\Signal\Models\Entity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use App\Mcp\Attributes\AssistantTool;

/**
 * MCP tool for retrieving all facts about a named entity from the knowledge graph.
 *
 * Returns current relationships (and optionally historical ones) for any entity
 * by name — useful for agents building context about a specific person or company.
 */
#[IsReadOnly]
#[AssistantTool('read')]
class KgEntityFactsTool extends Tool
{
    protected string $name = 'kg_entity_facts';

    protected string $description = 'Get all knowledge graph facts about a named entity (person, company, product, etc.). Returns current relationships by default. Set include_history=true to see the full timeline including invalidated facts (e.g. past job titles, old prices).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_name' => $schema->string()
                ->description('Name of the entity to look up, e.g. "Alice Chen" or "Acme Corp"')
                ->required(),
            'entity_type' => $schema->string()
                ->description('Entity type to narrow search: person | company | location | product | topic')
                ->enum(['person', 'company', 'location', 'product', 'topic']),
            'include_history' => $schema->boolean()
                ->description('Include invalidated historical facts (default: false)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'entity_name' => 'required|string|max:255',
            'entity_type' => 'nullable|string|in:person,company,location,product,topic',
            'include_history' => 'nullable|boolean',
        ]);

        try {
            $teamId = app('mcp.team_id');
            $canonicalName = Str::lower(Str::ascii(trim($validated['entity_name'])));

            $entityQuery = Entity::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('canonical_name', $canonicalName);

            if (! empty($validated['entity_type'])) {
                $entityQuery->where('type', $validated['entity_type']);
            }

            $entity = $entityQuery->first();

            if (! $entity) {
                return Response::text(json_encode([
                    'entity_name' => $validated['entity_name'],
                    'found' => false,
                    'message' => 'No entity found with this name in the knowledge graph.',
                ]));
            }

            $includeHistory = (bool) ($validated['include_history'] ?? false);

            /** @var TemporalKnowledgeGraphService $service */
            $service = app(TemporalKnowledgeGraphService::class);

            $facts = $includeHistory
                ? $service->getEntityTimeline($teamId, $entity->id)
                : $service->getCurrentFacts($teamId, $entity->id);

            $mapEdge = fn (KgEdge $edge) => [
                'id' => $edge->id,
                'source_entity' => $edge->sourceEntity?->name,
                'relation_type' => $edge->relation_type,
                'target_entity' => $edge->targetEntity?->name,
                'fact' => $edge->fact,
                'valid_at' => $edge->valid_at?->toIso8601String(),
                'invalid_at' => $edge->invalid_at?->toIso8601String(),
                'is_current' => $edge->invalid_at === null,
            ];

            return Response::text(json_encode([
                'entity_name' => $entity->name,
                'entity_type' => $entity->type,
                'entity_id' => $entity->id,
                'found' => true,
                'facts' => $facts->map($mapEdge)->values()->toArray(),
                'fact_count' => $facts->count(),
                'first_seen' => $entity->first_seen_at?->toIso8601String(),
                'last_seen' => $entity->last_seen_at?->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
