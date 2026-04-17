<?php

namespace App\Mcp\Tools\Artifact;

use App\Domain\Experiment\Services\ArtifactContentResolver;
use App\Mcp\Attributes\AssistantTool;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ArtifactDownloadTool extends Tool
{
    protected string $name = 'artifact_download_info';

    protected string $description = 'Get download metadata for an artifact: filename, MIME type, size, and the REST API download URL. Use this for large artifacts that exceed the MCP content limit.';

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

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $artifact = Artifact::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['artifact_id']);

        if (! $artifact) {
            return Response::error('Artifact not found.');
        }

        /** @var ArtifactVersion|null $version */
        $version = ! empty($validated['version'])
            ? $artifact->versions()->where('version', $validated['version'])->first()
            : $artifact->versions()->orderByDesc('version')->first();

        if (! $version) {
            return Response::error('Artifact has no versions.');
        }

        $content = (string) $version->content;

        $extension = ArtifactContentResolver::extension($artifact->type);
        $mime = ArtifactContentResolver::mimeType($artifact->type);
        $filename = Str::slug($artifact->name)."-v{$version->version}.{$extension}";
        $sizeBytes = mb_strlen($content, '8bit');

        return Response::text(json_encode([
            'artifact_id' => $artifact->id,
            'artifact_name' => $artifact->name,
            'version' => $version->version,
            'type' => $artifact->type,
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => $sizeBytes,
            'size_human' => $sizeBytes > 1048576
                ? round($sizeBytes / 1048576, 2).' MB'
                : round($sizeBytes / 1024, 2).' KB',
            'download_url' => url("/api/v1/artifacts/{$artifact->id}/download").($validated['version'] ?? false ? '?version='.$version->version : ''),
        ]));
    }
}
