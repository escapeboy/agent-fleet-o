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
 * Returns a compact signatures-only view of all code elements in a file.
 * Useful for agents that want to quickly survey what's in a file without
 * consuming the full source content in their context window.
 *
 * Returns both a structured `elements` array and a human-readable `summary`
 * string formatted as "[line X] type: signature".
 */
#[IsReadOnly]
#[IsIdempotent]
class CodeSkimFileTool extends Tool
{
    protected string $name = 'code_skim_file';

    protected string $description = 'Get a signatures-only view of a file — quickly see what\'s in a file without reading full content.';

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

        // Build compact text summary: "[line X] type: signature"
        $summaryLines = array_map(
            fn ($el) => sprintf(
                '[line %d] %s: %s',
                $el['line_start'],
                $el['element_type'],
                $el['signature'] ?? $el['name'],
            ),
            $structured,
        );

        $summary = empty($summaryLines)
            ? "No indexed code elements found in {$filePath}."
            : implode("\n", $summaryLines);

        return Response::text(json_encode([
            'file_path' => $filePath,
            'count'     => count($structured),
            'summary'   => $summary,
            'elements'  => $structured,
        ]));
    }
}
