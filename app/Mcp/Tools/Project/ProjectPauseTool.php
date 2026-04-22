<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Models\Project;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ProjectPauseTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'project_pause';

    protected string $description = 'Pause an active project. Disables its schedule and can be resumed later.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $project = Project::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['project_id']);

        if (! $project) {
            return $this->notFoundError('project');
        }

        try {
            $result = app(PauseProjectAction::class)->execute($project);

            return Response::text(json_encode([
                'success' => true,
                'project_id' => $result->id,
                'status' => $result->status->value,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
