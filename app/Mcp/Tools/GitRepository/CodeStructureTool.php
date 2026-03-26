<?php

declare(strict_types=1);

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\CodeSkimmingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Returns the structural outline of a file — classes, functions, and methods
 * with line numbers — without loading full source content. This mirrors the
 * HKUDS "code skimming" primitive for efficient agent context consumption.
 */
#[IsReadOnly]
#[IsIdempotent]
class CodeStructureTool extends Tool
{
    protected string $name = 'code_structure';

    protected string $description = 'Get the structure of a file in a Git repository — classes, functions, and methods with line numbers.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'git_repository_id' => $schema->string()
                ->description('UUID of the git repository')
                ->required(),
            'file_path' => $schema->string()
                ->description('Relative file path (e.g. app/Services/Foo.php)')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $repo = GitRepository::find($request->get('git_repository_id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        $filePath = (string) $request->get('file_path');

        $elements = app(CodeSkimmingService::class)->skimFile(
            $repo->team_id,
            $repo->id,
            $filePath,
        );

        $structured = $elements->map(fn ($el) => [
            'id'           => $el->id,
            'element_type' => $el->element_type,
            'name'         => $el->name,
            'line_start'   => $el->line_start,
            'line_end'     => $el->line_end,
            'signature'    => $el->signature,
        ])->values()->all();

        return Response::text(json_encode([
            'file_path' => $filePath,
            'count'     => count($structured),
            'elements'  => $structured,
        ]));
    }
}
