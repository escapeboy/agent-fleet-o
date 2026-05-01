<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Prism\Prism\Facades\Prism;

#[IsReadOnly]
#[AssistantTool('read')]
class KgCommunitySearchTool extends Tool
{
    protected string $name = 'kg_community_search';

    protected string $description = 'Search knowledge graph communities by semantic similarity to a query. Communities are clusters of related entities with LLM-generated summaries. Use for high-level "global" questions about what entities are related.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural language query to find relevant entity communities')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of communities to return (default: 5, max: 20)')
                ->default(5),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => 'required|string|max:500',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $teamId = app('mcp.team_id');
        $limit = min((int) ($validated['limit'] ?? 5), 20);

        $communities = $this->searchCommunities($teamId, $validated['query'], $limit);

        return Response::text(json_encode([
            'communities' => $communities,
            'count' => count($communities),
            'query' => $validated['query'],
        ]));
    }

    private function searchCommunities(string $teamId, string $query, int $limit): array
    {
        // Try pgvector cosine similarity first
        if (
            DB::getDriverName() === 'pgsql' &&
            Schema::hasColumn('kg_communities', 'summary_embedding')
        ) {
            $embedding = $this->generateEmbedding($query);
            if ($embedding !== null) {
                $vectorStr = '['.implode(',', $embedding).']';

                $rows = DB::select(
                    'SELECT id, label, summary, size, top_entities,
                            1 - (summary_embedding <=> ?::vector) AS similarity
                     FROM kg_communities
                     WHERE team_id = ?
                       AND summary_embedding IS NOT NULL
                     ORDER BY summary_embedding <=> ?::vector
                     LIMIT ?',
                    [$vectorStr, $teamId, $vectorStr, $limit],
                );

                return array_map(fn ($r) => [
                    'id' => $r->id,
                    'label' => $r->label,
                    'summary' => $r->summary,
                    'size' => $r->size,
                    'top_entities' => json_decode($r->top_entities, true),
                    'similarity' => round((float) $r->similarity, 4),
                ], $rows);
            }
        }

        // Fallback: LIKE on summary text
        $communities = KgCommunity::where('team_id', $teamId)
            ->where(function ($q) use ($query): void {
                $q->where('summary', 'LIKE', '%'.$query.'%')
                    ->orWhere('label', 'LIKE', '%'.$query.'%');
            })
            ->limit($limit)
            ->get();

        return $communities->map(fn (KgCommunity $c) => [
            'id' => $c->id,
            'label' => $c->label,
            'summary' => $c->summary,
            'size' => $c->size,
            'top_entities' => $c->top_entities,
            'similarity' => null,
        ])->values()->toArray();
    }

    private function generateEmbedding(string $text): ?array
    {
        try {
            $model = config('memory.embedding_model', 'text-embedding-3-small');

            $response = Prism::embeddings()
                ->using(config('memory.embedding_provider', 'openai'), $model)
                ->fromInput($text)
                ->asEmbeddings();

            return $response->embeddings[0]->embedding;
        } catch (\Throwable) {
            return null;
        }
    }
}
