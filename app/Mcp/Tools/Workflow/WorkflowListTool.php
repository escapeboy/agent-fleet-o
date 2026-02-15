<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WorkflowListTool extends Tool
{
    protected string $name = 'workflow_list';

    protected string $description = 'List workflows with optional status filter. Returns id, name, status, description, and node count.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: draft, active, archived')
                ->enum(['draft', 'active', 'archived']),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Workflow::query()->withCount('nodes')->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $workflows = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $workflows->count(),
            'workflows' => $workflows->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'status' => $w->status->value,
                'description' => $w->description,
                'nodes_count' => $w->nodes_count,
            ])->toArray(),
        ]));
    }
}
