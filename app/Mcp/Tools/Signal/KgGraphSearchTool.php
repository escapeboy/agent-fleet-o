<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Services\KnowledgeGraphTraversal;
use App\Domain\Memory\Models\Memory;
use App\Domain\Signal\Models\Entity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool for knowledge-graph-aware memory retrieval using LightRAG traversal modes.
 *
 * Supports three modes:
 *  - local:  1-to-N hop traversal from seed entities matched to the query.
 *  - global: High-centrality (most connected) entities reachable from seed entities.
 *  - hybrid: Union of local and global results, de-duplicated.
 *
 * Entity seeds are extracted by keyword-matching entity names against the query.
 * Falls back to an empty result set if no matching entities are found.
 */
#[IsReadOnly]
#[IsIdempotent]
class KgGraphSearchTool extends Tool
{
    protected string $name = 'kg_graph_search';

    protected string $description = 'Search memories using knowledge-graph traversal (LightRAG modes). Extracts entity seeds from the query, then retrieves related memories via local hop traversal, global high-centrality scoring, or a hybrid of both.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural language search query used to identify seed entities')
                ->required(),
            'mode' => $schema->string()
                ->description('Traversal mode: local=hop-based, global=centrality-based, hybrid=merged')
                ->enum(['local', 'global', 'hybrid'])
                ->default('local'),
            'hops' => $schema->integer()
                ->description('Number of hops for local mode (default: 1, min: 1, max: 3)')
                ->default(1),
            'top_k' => $schema->integer()
                ->description('Max high-centrality entities to consider for global mode (default: 20)')
                ->default(20),
            'limit' => $schema->integer()
                ->description('Max memory records to return (default: 20, max: 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => 'required|string|max:500',
            'mode' => 'nullable|string|in:local,global,hybrid',
            'hops' => 'nullable|integer|min:1|max:3',
            'top_k' => 'nullable|integer|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $teamId = app('mcp.team_id');
            $mode = $validated['mode'] ?? 'local';
            $hops = (int) ($validated['hops'] ?? 1);
            $topK = (int) ($validated['top_k'] ?? 20);
            $limit = min((int) ($validated['limit'] ?? 20), 100);

            $entityIds = $this->getQueryEntityIds($teamId, $validated['query']);

            if (empty($entityIds)) {
                return Response::text(json_encode([
                    'count' => 0,
                    'memories' => [],
                    'message' => 'No entities matched the query keywords. Try a simpler or different query.',
                    'mode' => $mode,
                    'query' => $validated['query'],
                ]));
            }

            $traversal = app(KnowledgeGraphTraversal::class);

            $memories = match ($mode) {
                'global' => $traversal->globalSearch($teamId, $entityIds, topK: $topK),
                'hybrid' => $traversal->localSearch($teamId, $entityIds, hops: $hops)
                    ->merge($traversal->globalSearch($teamId, $entityIds, topK: $topK))
                    ->unique('id'),
                default => $traversal->localSearch($teamId, $entityIds, hops: $hops),
            };

            $memories = $memories->take($limit);

            return Response::text(json_encode([
                'count' => $memories->count(),
                'mode' => $mode,
                'seed_entity_count' => count($entityIds),
                'query' => $validated['query'],
                'memories' => $memories->map(fn (Memory $m) => [
                    'id' => $m->id,
                    'content' => mb_substr($m->content, 0, 400),
                    'source_type' => $m->source_type,
                    'confidence' => $m->confidence,
                    'category' => $m->category?->value ?? null,
                    'tags' => $m->tags ?? [],
                    'created' => $m->created_at?->toIso8601String(),
                ])->values()->toArray(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * Extract entity UUIDs by matching query keywords (>3 chars) against entity names.
     *
     * @return string[]
     */
    private function getQueryEntityIds(string $teamId, string $query): array
    {
        $keywords = array_filter(
            explode(' ', Str::lower($query)),
            fn (string $word) => strlen($word) > 3,
        );

        if (empty($keywords)) {
            return [];
        }

        return Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $kw) {
                    $q->orWhere('name', 'LIKE', '%'.$kw.'%');
                }
            })
            ->limit(10)
            ->pluck('id')
            ->toArray();
    }
}
