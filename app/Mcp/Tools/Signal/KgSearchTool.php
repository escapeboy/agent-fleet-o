<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Actions\SearchKgFactsAction;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool for semantic search over temporal knowledge graph facts.
 *
 * Searches current (or historical) entity relationship facts using cosine similarity.
 * Useful for agents to find relevant context about entities before taking action.
 */
#[IsReadOnly]
class KgSearchTool extends Tool
{
    protected string $name = 'kg_search';

    protected string $description = 'Semantic search over the temporal knowledge graph. Finds entity relationship facts (e.g. "Alice works at Acme Corp", "Competitor X price is $99") using meaning-based search. Returns currently valid facts by default. Use include_history=true to include invalidated historical facts.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural language search query, e.g. "CEO of Acme Corp" or "latest price of Competitor X"')
                ->required(),
            'relation_type' => $schema->string()
                ->description('Filter by relation type (snake_case), e.g. works_at, has_price, has_status, acquired_by'),
            'entity_type' => $schema->string()
                ->description('Filter by source entity type: person | company | location | product | topic')
                ->enum(['person', 'company', 'location', 'product', 'topic']),
            'include_history' => $schema->boolean()
                ->description('Include invalidated historical facts (default: false — only current facts)'),
            'limit' => $schema->integer()
                ->description('Maximum number of results (default: 10, max: 50)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => 'required|string|max:500',
            'relation_type' => 'nullable|string|max:80',
            'entity_type' => 'nullable|string|in:person,company,location,product,topic',
            'include_history' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            /** @var SearchKgFactsAction $action */
            $action = app(SearchKgFactsAction::class);

            $facts = $action->execute(
                teamId: app('mcp.team_id'),
                query: $validated['query'],
                relationType: $validated['relation_type'] ?? null,
                entityType: $validated['entity_type'] ?? null,
                includeHistory: (bool) ($validated['include_history'] ?? false),
                limit: min((int) ($validated['limit'] ?? 10), 50),
            );

            $results = $facts->map(fn (KgEdge $edge) => [
                'id' => $edge->id,
                'source_entity' => $edge->sourceEntity?->name,
                'source_type' => $edge->sourceEntity?->type,
                'relation_type' => $edge->relation_type,
                'fact' => $edge->fact,
                'target_entity' => $edge->targetEntity?->name,
                'target_type' => $edge->targetEntity?->type,
                'valid_at' => $edge->valid_at?->toIso8601String(),
                'invalid_at' => $edge->invalid_at?->toIso8601String(),
                'similarity' => round((float) ($edge->similarity ?? 0), 4),
            ])->values()->toArray();

            return Response::text(json_encode([
                'facts' => $results,
                'count' => count($results),
                'query' => $validated['query'],
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
