<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetProjectTool implements Tool
{
    public function name(): string
    {
        return 'get_project';
    }

    public function description(): string
    {
        return 'Get detailed information about a specific project';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required()->description('The project UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $project = Project::with('runs')->find($request->get('project_id'));
        if (! $project) {
            return json_encode(['error' => 'Project not found']);
        }

        return json_encode([
            'id' => $project->id,
            'title' => $project->title,
            'type' => $project->type->value,
            'status' => $project->status->value,
            'description' => $project->description,
            'goal' => $project->goal,
            'recent_runs' => $project->runs->sortByDesc('created_at')->take(5)->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status->value,
                'created' => $r->created_at->diffForHumans(),
            ])->values()->toArray(),
            'created' => $project->created_at->toIso8601String(),
            'url' => route('projects.show', $project),
        ]);
    }
}
