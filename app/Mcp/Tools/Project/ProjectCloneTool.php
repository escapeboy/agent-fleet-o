<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ProjectCloneTool extends Tool
{
    protected string $name = 'project_clone';

    protected string $description = 'Clone an existing project with a new name.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->description('The project ID to clone.')->required(),
            'name' => $schema->string()->description('New name for the cloned project.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $project = Project::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('project_id'));
        if (! $project) {
            return Response::error('Project not found.');
        }

        $clone = $project->replicate();
        $clone->name = $request->get('name');
        $clone->status = ProjectStatus::Draft;
        $clone->save();

        return Response::text(json_encode([
            'success' => true,
            'id' => $clone->id,
            'name' => $clone->name,
            'status' => $clone->status->value,
            'cloned_from' => $project->id,
        ]));
    }
}
