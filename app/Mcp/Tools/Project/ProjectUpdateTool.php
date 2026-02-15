<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\UpdateProjectAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ProjectUpdateTool extends Tool
{
    protected string $name = 'project_update';

    protected string $description = 'Update an existing project. Only provided fields will be changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
            'title' => $schema->string()
                ->description('New project title'),
            'description' => $schema->string()
                ->description('New project description'),
            'goal' => $schema->string()
                ->description('New project goal'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'goal' => 'nullable|string',
        ]);

        $project = Project::find($validated['project_id']);

        if (! $project) {
            return Response::error('Project not found.');
        }

        $data = array_filter([
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'goal' => $validated['goal'] ?? null,
        ], fn ($v) => $v !== null);

        if (empty($data)) {
            return Response::error('No fields to update. Provide at least one of: title, description, goal.');
        }

        try {
            $result = app(UpdateProjectAction::class)->execute(
                project: $project,
                data: $data,
            );

            return Response::text(json_encode([
                'success' => true,
                'project_id' => $result->id,
                'title' => $result->title,
                'updated_fields' => array_keys($data),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
