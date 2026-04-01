<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ArchiveProjectTool implements Tool
{
    public function name(): string
    {
        return 'archive_project';
    }

    public function description(): string
    {
        return 'Archive a project permanently. This is a destructive action.';
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
            app(ArchiveProjectAction::class)->execute($project);

            return json_encode(['success' => true, 'message' => "Project '{$project->title}' archived."]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
