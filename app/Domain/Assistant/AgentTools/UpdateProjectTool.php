<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Actions\UpdateProjectAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class UpdateProjectTool implements Tool
{
    public function name(): string
    {
        return 'update_project';
    }

    public function description(): string
    {
        return 'Update an existing project title or description';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required()->description('The project UUID'),
            'title' => $schema->string()->description('New project title'),
            'description' => $schema->string()->description('New project description'),
        ];
    }

    public function handle(Request $request): string
    {
        $project = Project::find($request->get('project_id'));
        if (! $project) {
            return json_encode(['error' => 'Project not found']);
        }

        try {
            $data = array_filter(['title' => $request->get('title'), 'description' => $request->get('description')], fn ($v) => $v !== null);

            if (empty($data)) {
                return json_encode(['error' => 'No attributes provided to update']);
            }

            $project = app(UpdateProjectAction::class)->execute($project, $data);

            return json_encode([
                'success' => true,
                'project_id' => $project->id,
                'title' => $project->title,
                'status' => $project->status->value,
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
