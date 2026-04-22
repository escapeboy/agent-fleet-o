<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\RestartProjectAction;
use App\Domain\Project\Models\Project;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ProjectRestartTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'project_restart';

    protected string $description = 'Restart a project. Resets all run counters, milestones, and schedule state, then triggers a fresh run. Allowed from Completed, Failed, Paused, or Active status.';

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
            $project = app(RestartProjectAction::class)->execute($project);

            return Response::text(json_encode([
                'success' => true,
                'project_id' => $project->id,
                'status' => $project->status->value,
                'total_runs' => $project->total_runs,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
