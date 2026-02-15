<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\CreateProjectAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ProjectCreateTool extends Tool
{
    protected string $name = 'project_create';

    protected string $description = 'Create a new project. One-shot projects auto-start immediately; continuous projects require schedule configuration.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Project title')
                ->required(),
            'description' => $schema->string()
                ->description('Project description'),
            'type' => $schema->string()
                ->description('Project type: one_shot, continuous (default: one_shot)')
                ->enum(['one_shot', 'continuous'])
                ->default('one_shot'),
            'goal' => $schema->string()
                ->description('Project goal'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:one_shot,continuous',
            'goal' => 'nullable|string',
        ]);

        try {
            $project = app(CreateProjectAction::class)->execute(
                userId: auth()->id(),
                title: $validated['title'],
                type: $validated['type'] ?? 'one_shot',
                description: $validated['description'] ?? null,
                goal: $validated['goal'] ?? null,
                teamId: auth()->user()->current_team_id,
            );

            return Response::text(json_encode([
                'success' => true,
                'project_id' => $project->id,
                'title' => $project->title,
                'status' => $project->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
