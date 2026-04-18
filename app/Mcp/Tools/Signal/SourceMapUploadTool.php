<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Actions\UploadSourceMapAction;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
#[AssistantTool('write')]
class SourceMapUploadTool extends Tool
{
    protected string $name = 'source_map_upload';

    protected string $description = 'Upload a JavaScript source map for a project release. Used by CI/CD pipelines to enable server-side stack trace resolution for bug reports.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('Project key (e.g. "chatbot", "client-platform")'),
            'release' => $schema->string()
                ->description('Git commit SHA or version tag for this source map'),
            'map_data' => $schema->string()
                ->description('Raw source map JSON string (the contents of the .map file)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $mapData = json_decode($request->get('map_data'), true);

        if (! is_array($mapData)) {
            return Response::text(json_encode(['error' => 'Invalid source map JSON']));
        }

        $sourceMap = app(UploadSourceMapAction::class)->execute(
            teamId: $teamId,
            project: $request->get('project'),
            release: $request->get('release'),
            mapData: $mapData,
        );

        return Response::text(json_encode([
            'id' => $sourceMap->id,
            'project' => $sourceMap->project,
            'release' => $sourceMap->release,
            'status' => 'uploaded',
        ]));
    }
}
