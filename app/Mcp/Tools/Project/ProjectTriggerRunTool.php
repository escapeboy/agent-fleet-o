<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

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

        $project = Project::find($validated['project_id']);

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
