<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WorkflowGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_get';

    protected string $description = 'Get detailed information about a specific workflow including its nodes and edges.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['workflow_id' => 'required|string']);

        $workflow = Workflow::with(['nodes', 'edges'])->find($validated['workflow_id']);

        if (! $workflow) {
            return $this->notFoundError('workflow');
        }

        return Response::text(json_encode([
            'id' => $workflow->id,
            'name' => $workflow->name,
            'status' => $workflow->status->value,
            'description' => $workflow->description,
            'version' => $workflow->version,
            'nodes' => $workflow->nodes->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type->value,
                'label' => $n->label,
                'agent_id' => $n->agent_id,
                'skill_id' => $n->skill_id,
                'crew_id' => $n->crew_id,
                'config' => $n->config,
                'expression' => $n->expression,
                'position_x' => $n->position_x,
                'position_y' => $n->position_y,
                'order' => $n->order,
            ])->toArray(),
            'edges' => $workflow->edges->map(fn ($e) => [
                'id' => $e->id,
                'source_node_id' => $e->source_node_id,
                'target_node_id' => $e->target_node_id,
                'label' => $e->label,
                'condition' => $e->condition,
                'case_value' => $e->case_value,
                'is_default' => $e->is_default,
                'source_channel' => $e->source_channel,
                'target_channel' => $e->target_channel,
            ])->toArray(),
            'created_at' => $workflow->created_at?->toIso8601String(),
        ]));
    }
}
