<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListProjectsTool implements Tool
{
    public function name(): string
    {
        return 'list_projects';
    }

    public function description(): string
    {
        return 'List projects with optional status filter';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Filter by status (e.g. draft, active, paused, completed, archived)'),
            'limit' => $schema->integer()->description('Max results to return (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = Project::query()->orderByDesc('created_at');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $projects = $query->limit($request->get('limit', 10))->get(['id', 'title', 'type', 'status', 'created_at']);

        return json_encode([
            'count' => $projects->count(),
            'projects' => $projects->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'type' => $p->type->value,
                'status' => $p->status->value,
                'created' => $p->created_at->diffForHumans(),
            ])->toArray(),
        ]);
    }
}
