<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ProjectActivateTool extends Tool
{
    protected string $name = 'project_activate';

    protected string $description = 'Activate a draft or failed project, enabling it to run. The project must be in draft or failed status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID to activate')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $projectId = $request->get('project_id');
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $project = Project::withoutGlobalScopes()->where('team_id', $teamId)->find($projectId);

        if (! $project) {
            return Response::error("Project {$projectId} not found. Use project_list to discover valid project IDs.");
        }

        if (! $project->status->canTransitionTo(ProjectStatus::Active)) {
            return Response::error("Cannot activate project in '{$project->status->value}' status. Only draft or failed projects can be activated.");
        }

        try {
            DB::transaction(function () use ($project) {
                $project->update(['status' => ProjectStatus::Active]);

                // Re-enable schedule if one exists and project is continuous
                if ($project->schedule) {
                    $project->schedule->update(['enabled' => true]);
                }
            });

            $project->refresh();

            return Response::text(json_encode([
                'success' => true,
                'project_id' => $project->id,
                'title' => $project->title,
                'status' => $project->status->value,
                'activated_at' => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
