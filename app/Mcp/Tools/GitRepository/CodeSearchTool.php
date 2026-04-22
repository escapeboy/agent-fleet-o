<?php

declare(strict_types=1);

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\CodeRetriever;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Hybrid semantic + keyword search over indexed code elements in a Git repository.
 * Uses pgvector cosine similarity (semantic) and tsvector ts_rank (keyword) when
 * available, falling back to LIKE-based search on SQLite/test environments.
 */
#[IsReadOnly]
#[IsIdempotent]
class CodeSearchTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'code_search';

    protected string $description = 'Search code elements (classes, functions, methods) in a Git repository using hybrid semantic + keyword search.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'git_repository_id' => $schema->string()
                ->description('UUID of the git repository')
                ->required(),
            'query' => $schema->string()
                ->description('Natural-language or identifier search query')
                ->required(),
            'element_type' => $schema->string()
                ->description('Filter by element type: class, function, method, or file')
                ->enum(['class', 'function', 'method', 'file']),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return (1–20)')
                ->minimum(1)
                ->maximum(20)
                ->default(5),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id');
        $repo = GitRepository::where('team_id', $teamId)->find($request->get('git_repository_id'));

        if (! $repo) {
            return $this->notFoundError('repository');
        }

        $limit = (int) ($request->get('limit') ?? 5);
        $limit = max(1, min(20, $limit));

        /** @var Collection $elements */
        $elements = app(CodeRetriever::class)->search(
            $teamId,
            $repo->id,
            (string) $request->get('query'),
            $limit,
        );

        // Apply optional element_type filter after retrieval (index-level filter not in service).
        $elementType = $request->get('element_type');
        if ($elementType !== null) {
            $elements = $elements->filter(fn ($el) => $el->element_type === $elementType)->values();
        }

        $results = $elements->map(fn ($el) => [
            'id' => $el->id,
            'name' => $el->name,
            'element_type' => $el->element_type,
            'file_path' => $el->file_path,
            'line_start' => $el->line_start,
            'line_end' => $el->line_end,
            'signature' => $el->signature,
        ])->values()->all();

        return Response::text(json_encode([
            'query' => $request->get('query'),
            'count' => count($results),
            'results' => $results,
        ]));
    }
}
