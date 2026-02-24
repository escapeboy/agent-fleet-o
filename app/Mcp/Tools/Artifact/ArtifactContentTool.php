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
class ArtifactContentTool extends Tool
{
    protected string $name = 'artifact_content';

    protected string $description = 'Get the raw content of an artifact version. Content is truncated at 100KB for MCP transport. For large artifacts use artifact_download_info instead.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact_id' => $schema->string()
                ->description('The artifact UUID')
                ->required(),
            'version' => $schema->integer()
                ->description('Specific version number (default: latest)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'artifact_id' => 'required|string',
            'version' => 'sometimes|integer|min:1',
        ]);

        $artifact = Artifact::find($validated['artifact_id']);

        if (! $artifact) {
            return Response::error('Artifact not found.');
        }

        $version = ! empty($validated['version'])
            ? $artifact->versions()->where('version', $validated['version'])->first()
            : $artifact->versions()->orderByDesc('version')->first();

        if (! $version) {
            return Response::error('Artifact has no versions.');
        }

        $content = is_string($version->content)
            ? $version->content
            : json_encode($version->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $truncated = false;
        if (mb_strlen($content) > 100000) {
            $content = mb_substr($content, 0, 100000).'... [truncated — use artifact_download_info for full content]';
            $truncated = true;
        }

        return Response::text(json_encode([
            'artifact_id' => $artifact->id,
            'artifact_name' => $artifact->name,
            'version' => $version->version,
            'type' => $artifact->type,
            'category' => ArtifactContentResolver::category($artifact->type, $content),
            'truncated' => $truncated,
            'content' => $content,
        ]));
    }
}
