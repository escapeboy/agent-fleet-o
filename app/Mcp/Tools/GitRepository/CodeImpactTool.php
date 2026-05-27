<?php

declare(strict_types=1);

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\CodeElement;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\CodeGraphTraversal;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Impact analysis: given a CodeElement, find everything that DEPENDS ON it
 * (incoming callers / importers / subclasses) up to N hops — "what could break
 * if I change this symbol". Inverse of code_call_chain (which walks outgoing).
 *
 * Requires populated code_edges (currently produced for non-PHP source by the
 * polyglot CodeGraph extractor; PHP edge extraction is a separate future task).
 */
#[IsReadOnly]
#[IsIdempotent]
class CodeImpactTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'code_impact';

    protected string $description = 'Find what depends on a code element (incoming callers/importers/subclasses) — the blast radius of changing it.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'git_repository_id' => $schema->string()
                ->description('UUID of the git repository')
                ->required(),
            'element_id' => $schema->string()
                ->description('UUID of the CodeElement to analyze impact for')
                ->required(),
            'hops' => $schema->integer()
                ->description('Dependency depth to traverse (1–4, default 2)')
                ->min(1)
                ->max(4)
                ->default(2),
            'edge_type' => $schema->string()
                ->description('Filter by edge type: calls, imports, or inherits')
                ->enum(['calls', 'imports', 'inherits']),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id');
        $repo = GitRepository::where('team_id', $teamId)->find($request->get('git_repository_id'));

        if (! $repo) {
            return $this->notFoundError('repository');
        }

        $elementId = (string) $request->get('element_id');
        $target = CodeElement::where('team_id', $teamId)
            ->where('git_repository_id', $repo->id)
            ->find($elementId);

        if (! $target) {
            return $this->notFoundError('code element');
        }

        $hops = max(1, min(4, (int) ($request->get('hops') ?? 2)));
        $edgeType = $request->get('edge_type') ?: null;

        $affected = app(CodeGraphTraversal::class)->traverse(
            $teamId,
            $elementId,
            $hops,
            $edgeType,
            'in',
        );

        $results = $affected->map(fn ($el) => [
            'id' => $el->id,
            'name' => $el->name,
            'element_type' => $el->element_type,
            'file_path' => $el->file_path,
            'line_start' => $el->line_start,
            'line_end' => $el->line_end,
            'signature' => $el->signature,
        ])->values()->all();

        return Response::text(json_encode([
            'element_id' => $elementId,
            'element_name' => $target->name,
            'hops' => $hops,
            'edge_type' => $edgeType,
            'affected_count' => count($results),
            'affected' => $results,
        ]));
    }
}
