<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MemoryUnifiedSearchTool extends Tool
{
    protected string $name = 'memory_unified_search';

    protected string $description = 'Unified search across vector memory, knowledge graph, and keyword search using Reciprocal Rank Fusion. Returns ranked results with source attribution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural language search query')
                ->required(),
            'agent_id' => $schema->string()
                ->description('Filter by agent UUID (optional)'),
            'project_id' => $schema->string()
                ->description('Filter by project UUID (optional)'),
            'top_k' => $schema->integer()
                ->description('Max results to return (default 10, max 50)')
                ->default(10),
            'tags' => $schema->array()
                ->description('Filter by tags — only return memories containing ANY of these tags. E.g. ["barsy:client", "barsy:shared"]. Omit to return all.'),
            'topic' => $schema->string()
                ->description('Namespace pre-filter by topic slug, e.g. "auth_migration". Narrows vector search to a named context before scoring.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['query' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'No team context']));
        }

        $action = app(UnifiedMemorySearchAction::class);

        $topK = min((int) ($request->get('top_k', 10)), 50);

        $tags = $request->get('tags');
        $tags = is_array($tags) && ! empty($tags) ? $tags : null;

        $topic = $request->get('topic');

        $results = $action->execute(
            teamId: $teamId,
            query: $validated['query'],
            agentId: $request->get('agent_id'),
            projectId: $request->get('project_id'),
            topK: $topK,
            tags: $tags,
            topic: is_string($topic) && $topic !== '' ? $topic : null,
        );

        return Response::text(json_encode([
            'count' => $results->count(),
            'results' => $results->map(fn ($item) => [
                'type' => $item['type'],
                'content' => $item['content'],
                'rrf_score' => round($item['score'], 6),
                'metadata' => $item['metadata'],
            ])->toArray(),
        ]));
    }
}
