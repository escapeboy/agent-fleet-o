<?php

declare(strict_types=1);

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\CodeSkimmingService;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
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
    use HasStructuredErrors;

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
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null);
        $repo = GitRepository::where('team_id', $teamId)->find($request->get('git_repository_id'));

        if (! $repo) {
            return $this->notFoundError('repository');
        }

        $filePath = (string) $request->get('file_path');

        $elements = app(CodeSkimmingService::class)->skimFile(
            $teamId,
            $repo->id,
            $filePath,
        );

        // Per-element call-graph "trail" counts (calls made / callers), so an agent
        // surveying the file can spot hub symbols. Zero everywhere until code_edges
        // is populated (today: non-PHP source via the polyglot extractor).
        $elementIds = $elements->pluck('id')->all();
        $callsOut = $this->edgeCounts($teamId, $elementIds, 'source_id');
        $calledBy = $this->edgeCounts($teamId, $elementIds, 'target_id');

        $structured = $elements->map(fn ($el) => [
            'id' => $el->id,
            'element_type' => $el->element_type,
            'name' => $el->name,
            'line_start' => $el->line_start,
            'line_end' => $el->line_end,
            'signature' => $el->signature,
            'calls_out' => (int) ($callsOut[$el->id] ?? 0),
            'called_by' => (int) ($calledBy[$el->id] ?? 0),
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
            'count' => count($structured),
            'summary' => $summary,
            'elements' => $structured,
        ]));
    }

    /**
     * Count edges per element on the given column ('source_id' for outgoing calls,
     * 'target_id' for incoming callers), filtered to call edges.
     *
     * @param  list<string>  $elementIds
     * @return array<string, int> element id → count
     */
    private function edgeCounts(string $teamId, array $elementIds, string $column): array
    {
        if ($elementIds === []) {
            return [];
        }

        return DB::table('code_edges')
            ->where('team_id', $teamId)
            ->where('edge_type', 'calls')
            ->whereIn($column, $elementIds)
            ->selectRaw("{$column} as element_id, count(*) as c")
            ->groupBy($column)
            ->pluck('c', 'element_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }
}
