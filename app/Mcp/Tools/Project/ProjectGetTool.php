<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Models\Project;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ProjectGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'project_get';

    protected string $description = 'Get detailed information about a specific project including description, goal, and recent runs.';

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
        $validated = $request->validate(['project_id' => 'required|string']);

        $project = Project::with('runs')->find($validated['project_id']);

        if (! $project) {
            return $this->notFoundError('project');
        }

        return Response::text(json_encode([
            'id' => $project->id,
            'title' => $project->title,
            'type' => $project->type->value,
            'status' => $project->status->value,
            'description' => $project->description,
            'goal' => $project->goal,
            'recent_runs' => $project->runs->take(5)->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status->value,
                'created' => $r->created_at?->diffForHumans(),
            ])->toArray(),
            'created_at' => $project->created_at?->toIso8601String(),
        ]));
    }
}
