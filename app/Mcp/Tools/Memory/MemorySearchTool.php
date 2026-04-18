<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\KnowledgeGraph\Services\KnowledgeGraphTraversal;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Models\Memory;
use App\Domain\Signal\Models\Entity;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MemorySearchTool extends Tool
{
    protected string $name = 'memory_search';

    protected string $description = 'Search agent memories by keyword with configurable retrieval modes. Supports flat keyword search (semantic), graph-local traversal, global high-centrality, or hybrid combinations.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search keyword to match against memory content')
                ->required(),
            'agent_id' => $schema->string()
                ->description('Filter by agent UUID'),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
            'min_confidence' => $schema->number()
                ->description('Minimum confidence score to filter results (0.0–1.0, default 0.0 to include all)')
                ->default(0.0),
            'category' => $schema->string()
                ->description('Filter by memory category: preference, knowledge, context, behavior, goal'),
            'search_mode' => $schema->string()
                ->description('Retrieval mode: semantic=flat keyword search, local=1-hop graph traversal from matched entities, global=high-centrality entities, hybrid=semantic+local merged, mix=semantic+global merged')
                ->enum(['semantic', 'local', 'global', 'hybrid', 'mix'])
                ->default('semantic'),
            'tags' => $schema->array()
                ->description('Filter by tags — only return memories containing ANY of these tags. E.g. ["barsy:client", "barsy:shared"]. Omit to return all memories regardless of tags.'),
            'topic' => $schema->string()
                ->description('Namespace pre-filter by topic slug, e.g. "auth_migration". Narrows the search to a named context before the vector scan for higher precision.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['query' => 'required|string']);

        $teamId = app('mcp.team_id');

        $searchMode = $request->get('search_mode', 'semantic');

        // For graph-based modes, attempt entity-seeded traversal first
        if (in_array($searchMode, ['local', 'global', 'hybrid', 'mix'], true)) {
            $entityIds = $this->getQueryEntityIds($teamId, $validated['query']);

            if (! empty($entityIds)) {
                $traversal = app(KnowledgeGraphTraversal::class);
                $limit = min((int) ($request->get('limit', 10)), 100);

                $memories = match ($searchMode) {
                    'local' => $traversal->localSearch($teamId, $entityIds, hops: 1),
                    'global' => $traversal->globalSearch($teamId, $entityIds, topK: 20),
                    'hybrid' => $this->semanticSearch($teamId, $request, $validated['query'])
                        ->merge($traversal->localSearch($teamId, $entityIds))
                        ->unique('id'),
                    'mix' => $this->semanticSearch($teamId, $request, $validated['query'])
                        ->merge($traversal->globalSearch($teamId, $entityIds))
                        ->unique('id'),
                };

                if ($memories->isNotEmpty()) {
                    return $this->formatMemories($memories->take($limit));
                }
            }

            // Fall through to semantic search if no entities matched or graph returned empty
        }

        // Default: flat keyword search
        $limit = min((int) ($request->get('limit', 10)), 100);
        $memories = $this->semanticSearch($teamId, $request, $validated['query'])
            ->take($limit);

        return $this->formatMemories($memories);
    }

    /**
     * Perform a keyword (ilike) search on memories, applying optional agent/confidence/category filters.
     *
     * @return Collection<int, Memory>
     */
    private function semanticSearch(string $teamId, Request $request, string $queryText): Collection
    {
        $query = Memory::withoutGlobalScopes()
            ->with(['agent:id,name', 'project:id,title'])
            ->where('team_id', $teamId)
            ->where('content', 'ilike', '%'.addcslashes($queryText, '%_').'%')
            ->orderByDesc('created_at');

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        $minConfidence = (float) $request->get('min_confidence', 0.0);
        if ($minConfidence > 0.0) {
            $query->where('confidence', '>=', $minConfidence);
        }

        if ($categoryValue = $request->get('category')) {
            $category = MemoryCategory::tryFrom($categoryValue);
            if ($category !== null) {
                $query->where('category', $category->value);
            }
        }

        $tags = $request->get('tags');
        if (is_array($tags) && ! empty($tags)) {
            // PostgreSQL JSONB ?| operator: matches memories containing ANY of the given tags
            $query->whereRaw('tags ?| ?', ['{'.implode(',', $tags).'}']);
        }

        if ($topic = $request->get('topic')) {
            $query->where('topic', $topic);
        }

        return $query->limit(100)->get();
    }

    /**
     * Look up entity UUIDs whose names contain keywords from the query string.
     *
     * Uses simple substring matching on entity names for words longer than 3 characters.
     * Returns an empty array when no keywords are extractable or no entities match.
     *
     * @return string[]
     */
    private function getQueryEntityIds(string $teamId, string $query): array
    {
        if (empty($teamId)) {
            return [];
        }

        $keywords = array_filter(
            explode(' ', strtolower($query)),
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

    /**
     * Serialize a collection of Memory models to a standard MCP Response.
     *
     * @param  Collection<int, Memory>  $memories
     */
    private function formatMemories(Collection $memories): Response
    {
        return Response::text(json_encode([
            'count' => $memories->count(),
            'memories' => $memories->map(fn (Memory $m) => [
                'id' => $m->id,
                'agent' => $m->agent?->name,
                'project' => $m->project?->title,
                'source_type' => $m->source_type,
                'content' => mb_substr($m->content, 0, 300),
                'confidence' => $m->confidence,
                'category' => $m->category?->value,
                'tags' => $m->tags ?? [],
                'created' => $m->created_at?->diffForHumans(),
            ])->values()->toArray(),
        ]));
    }
}
