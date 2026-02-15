<?php

namespace App\Mcp\Tools\Artifact;

use App\Domain\Experiment\Services\ArtifactContentResolver;
use App\Models\Artifact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ArtifactGetTool extends Tool
{
    protected string $name = 'artifact_get';

    protected string $description = 'Get a specific artifact with its content. Returns the latest version content by default, or a specific version if requested.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact_id' => $schema->string()
                ->description('The artifact UUID')
                ->required(),
            'version' => $schema->integer()
                ->description('Specific version number (default: latest)'),
            'include_content' => $schema->boolean()
                ->description('Include full content in response (default true). Set false for metadata only.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'artifact_id' => 'required|string',
            'version' => 'sometimes|integer|min:1',
            'include_content' => 'sometimes|boolean',
        ]);

        $artifact = Artifact::withCount('versions')->find($validated['artifact_id']);

        if (! $artifact) {
            return Response::error('Artifact not found.');
        }

        $version = ! empty($validated['version'])
            ? $artifact->versions()->where('version', $validated['version'])->first()
            : $artifact->versions()->orderByDesc('version')->first();

        if (! $version) {
            return Response::error('Artifact version not found.');
        }

        $includeContent = $validated['include_content'] ?? true;

        $content = null;
        if ($includeContent) {
            $content = is_string($version->content)
                ? $version->content
                : json_encode($version->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Truncate very large content for MCP context
            if (mb_strlen($content) > 50000) {
                $content = mb_substr($content, 0, 50000).'... [truncated]';
            }
        }

        return Response::text(json_encode([
            'id' => $artifact->id,
            'name' => $artifact->name,
            'type' => $artifact->type,
            'category' => ArtifactContentResolver::category($artifact->type, $content),
            'current_version' => $artifact->current_version,
            'versions_count' => $artifact->versions_count,
            'experiment_id' => $artifact->experiment_id,
            'crew_execution_id' => $artifact->crew_execution_id,
            'project_run_id' => $artifact->project_run_id,
            'version' => $version->version,
            'content' => $content,
            'preview_url' => route('artifacts.render', [$artifact->id, $version->version]),
            'created_at' => $artifact->created_at?->toIso8601String(),
        ]));
    }
}
