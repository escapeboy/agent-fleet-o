<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListWorkflowsTool implements Tool
{
    public function name(): string
    {
        return 'list_workflows';
    }

    public function description(): string
    {
        return 'List workflow templates';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Filter by status (e.g. draft, active, archived)'),
            'limit' => $schema->integer()->description('Max results to return (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = Workflow::query()->withCount('nodes')->orderBy('name');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $workflows = $query->limit($request->get('limit', 10))->get();

        return json_encode([
            'count' => $workflows->count(),
            'workflows' => $workflows->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'status' => $w->status->value,
                'nodes_count' => $w->nodes_count,
            ])->toArray(),
        ]);
    }
}
