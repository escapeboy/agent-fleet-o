<?php

namespace App\Mcp\Tools\Tool;

use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Semantic search over all registered MCP tools.
 *
 * Use this when unsure which tool to call — search by intent or keyword,
 * then call the matching tool by its exact name.
 */
#[IsReadOnly]
#[IsIdempotent]
class ToolSearchTool extends Tool
{
    protected string $name = 'tool_search';

    protected string $description = 'Semantic search over all available MCP tools by intent or keyword. Returns matching tools with name, group, description, and input schema. Use when unsure which tool to call — e.g. tool_search("execute a workflow") returns workflow_* tools.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural language description of what you want to do, e.g. "execute a workflow" or "check remaining budget"')
                ->required(),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 20)')
                ->default(10),
            'group' => $schema->string()
                ->description('Optional: filter by tool group, e.g. "agent", "experiment", "workflow", "approval"'),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = trim((string) $request->get('query', ''));
        $limit = min((int) ($request->get('limit', 10)), 20);
        $group = $request->get('group');

        if ($query === '') {
            return Response::text(json_encode(['count' => 0, 'tools' => [], 'tip' => 'Provide a non-empty query.']));
        }

        // Fast keyword match
        $results = DB::table('tool_registry_entries')
            ->when($group, fn ($q) => $q->where('group', $group))
            ->where(function ($q) use ($query) {
                $q->whereRaw('tool_name LIKE ?', ["%{$query}%"])
                    ->orWhereRaw('description LIKE ?', ["%{$query}%"]);
            })
            ->limit($limit)
            ->get(['tool_name', 'group', 'description', 'schema']);

        // Semantic fallback when keyword match is insufficient and pgvector is available
        if ($results->count() < min(3, $limit) && $this->hasVectorExtension()) {
            $results = $this->semanticSearch($query, $limit, $group);
        }

        return Response::text(json_encode([
            'count' => $results->count(),
            'tools' => $results->map(fn ($t) => [
                'name' => $t->tool_name,
                'group' => $t->group,
                'description' => $t->description,
                'schema' => is_string($t->schema) ? json_decode($t->schema, true) : $t->schema,
            ])->values()->toArray(),
            'tip' => 'Call the tool by its exact name field.',
        ]));
    }

    /** @return Collection<int, object> */
    private function semanticSearch(string $query, int $limit, ?string $group): Collection
    {
        try {
            $embeddingService = app(EmbeddingService::class);
            $vector = $embeddingService->formatForPgvector($embeddingService->embed($query));

            $groupFilter = $group ? "AND \"group\" = '".addslashes($group)."'" : '';

            $rows = DB::select(
                "SELECT tool_name, \"group\", description, schema,
                        1 - (embedding <=> ?::vector) AS similarity
                 FROM tool_registry_entries
                 WHERE embedding IS NOT NULL
                   AND 1 - (embedding <=> ?::vector) > 0.65
                   {$groupFilter}
                 ORDER BY embedding <=> ?::vector
                 LIMIT ?",
                [$vector, $vector, $vector, $limit],
            );

            return collect($rows);
        } catch (\Throwable) {
            return collect();
        }
    }

    private function hasVectorExtension(): bool
    {
        static $checked = null;

        if ($checked === null) {
            $checked = DB::getDriverName() === 'pgsql'
                && DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0;
        }

        return $checked;
    }
}
