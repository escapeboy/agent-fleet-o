<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class TriggerProjectRunTool implements Tool
{
    public function name(): string
    {
        return 'trigger_project_run';
    }

    public function description(): string
    {
        return 'Trigger a new run for a project';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required()->description('The project UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $project = Project::find($request->get('project_id'));
        if (! $project) {
            return json_encode(['error' => 'Project not found']);
        }

        try {
            $run = app(TriggerProjectRunAction::class)->execute($project, 'assistant');

            return json_encode([
                'success' => true,
                'run_id' => $run->id,
                'message' => "Project run triggered for '{$project->title}'.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
