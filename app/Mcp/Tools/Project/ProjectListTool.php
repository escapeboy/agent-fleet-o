<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ProjectListTool extends Tool
{
    protected string $name = 'project_list';

    protected string $description = 'List projects with optional status and type filters. Returns id, title, type, status, created_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: draft, active, paused, completed, archived, failed')
                ->enum(['draft', 'active', 'paused', 'completed', 'archived', 'failed']),
            'type' => $schema->string()
                ->description('Filter by type: one_shot, continuous')
                ->enum(['one_shot', 'continuous']),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Project::query()->orderByDesc('created_at');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $projects = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $projects->count(),
            'projects' => $projects->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'type' => $p->type->value,
                'status' => $p->status->value,
                'created_at' => $p->created_at?->diffForHumans(),
            ])->toArray(),
        ]));
    }
}
