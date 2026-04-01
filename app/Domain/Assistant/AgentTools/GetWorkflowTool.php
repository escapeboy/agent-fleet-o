<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetWorkflowTool implements Tool
{
    public function name(): string
    {
        return 'get_workflow';
    }

    public function description(): string
    {
        return 'Get detailed information about a specific workflow';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()->required()->description('The workflow UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $workflow = Workflow::with('nodes', 'edges')->find($request->get('workflow_id'));
        if (! $workflow) {
            return json_encode(['error' => 'Workflow not found']);
        }

        return json_encode([
            'id' => $workflow->id,
            'name' => $workflow->name,
            'status' => $workflow->status->value,
            'description' => $workflow->description,
            'nodes' => $workflow->nodes->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type->value,
                'label' => $n->label,
            ])->toArray(),
            'edges_count' => $workflow->edges->count(),
            'url' => route('workflows.show', $workflow),
        ]);
    }
}
