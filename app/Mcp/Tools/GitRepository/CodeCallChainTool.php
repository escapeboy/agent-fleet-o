<?php

declare(strict_types=1);

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\CodeGraphTraversal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * N-hop traversal of the code call/import/inheritance graph starting from a
 * given CodeElement. Uses a recursive CTE on PostgreSQL and a PHP-level BFS
 * on SQLite/test environments.
 */
#[IsReadOnly]
#[IsIdempotent]
class CodeCallChainTool extends Tool
{
    protected string $name = 'code_call_chain';

    protected string $description = 'Traverse the call/import/inheritance graph from a code element to find related code.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'git_repository_id' => $schema->string()
                ->description('UUID of the git repository')
                ->required(),
            'element_id' => $schema->string()
                ->description('UUID of the starting CodeElement')
                ->required(),
            'hops' => $schema->integer()
                ->description('Traversal depth (1–4, default 2)')
                ->minimum(1)
                ->maximum(4)
                ->default(2),
            'edge_type' => $schema->string()
                ->description('Filter by edge type: calls, imports, or inherits')
                ->enum(['calls', 'imports', 'inherits']),
        ];
    }

    public function handle(Request $request): Response
    {
        $repo = GitRepository::find($request->get('git_repository_id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        $hops     = (int) ($request->get('hops') ?? 2);
        $hops     = max(1, min(4, $hops));
        $edgeType = $request->get('edge_type') ?: null;

        $elements = app(CodeGraphTraversal::class)->traverse(
            $repo->team_id,
            (string) $request->get('element_id'),
            $hops,
            $edgeType,
        );

        $results = $elements->map(fn ($el) => [
            'id'           => $el->id,
            'name'         => $el->name,
            'element_type' => $el->element_type,
            'file_path'    => $el->file_path,
            'line_start'   => $el->line_start,
            'line_end'     => $el->line_end,
            'signature'    => $el->signature,
        ])->values()->all();

        return Response::text(json_encode([
            'start_element_id' => $request->get('element_id'),
            'hops'             => $hops,
            'edge_type'        => $edgeType,
            'count'            => count($results),
            'elements'         => $results,
        ]));
    }
}
