<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ProjectTriggerRunTool extends Tool
{
    protected string $name = 'project_trigger_run';

    protected string $description = 'Trigger a new run for a project. Creates an experiment and starts the pipeline.';

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
            return Response::error('No current team.');
        }
        $project = Project::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['project_id']);

        if (! $project) {
            return Response::error('Project not found.');
        }

        try {
            $run = app(TriggerProjectRunAction::class)->execute($project, 'mcp');

            return Response::text(json_encode([
                'success' => true,
                'run_id' => $run->id,
                'status' => $run->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
