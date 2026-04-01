<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class PauseProjectTool implements Tool
{
    public function name(): string
    {
        return 'pause_project';
    }

    public function description(): string
    {
        return 'Pause an active project and its schedule';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required()->description('The project UUID'),
            'reason' => $schema->string()->description('Optional reason for pausing'),
        ];
    }

    public function handle(Request $request): string
    {
        $project = Project::find($request->get('project_id'));
        if (! $project) {
            return json_encode(['error' => 'Project not found']);
        }

        try {
            app(PauseProjectAction::class)->execute($project, $request->get('reason'));

            return json_encode(['success' => true, 'message' => "Project '{$project->title}' paused."]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
