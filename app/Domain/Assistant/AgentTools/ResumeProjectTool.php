<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ResumeProjectTool implements Tool
{
    public function name(): string
    {
        return 'resume_project';
    }

    public function description(): string
    {
        return 'Resume a paused project and re-enable its schedule';
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
            app(ResumeProjectAction::class)->execute($project);

            return json_encode(['success' => true, 'message' => "Project '{$project->title}' resumed."]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
