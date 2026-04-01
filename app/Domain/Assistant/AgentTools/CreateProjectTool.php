<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Actions\CreateProjectAction;
use App\Domain\Project\Enums\ProjectType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateProjectTool implements Tool
{
    public function name(): string
    {
        return 'create_project';
    }

    public function description(): string
    {
        return 'Create a new project in FleetQ';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Project title'),
            'description' => $schema->string()->description('Project description'),
            'type' => $schema->string()->description('Project type: one_shot or continuous (default: one_shot)'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $type = $request->get('type');

            $project = app(CreateProjectAction::class)->execute(
                userId: auth()->id(),
                title: $request->get('title'),
                type: $type && ProjectType::tryFrom($type) ? $type : ProjectType::OneShot->value,
                description: $request->get('description'),
                teamId: auth()->user()->current_team_id,
            );

            return json_encode([
                'success' => true,
                'project_id' => $project->id,
                'title' => $project->title,
                'status' => $project->status->value,
                'url' => route('projects.show', $project),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
