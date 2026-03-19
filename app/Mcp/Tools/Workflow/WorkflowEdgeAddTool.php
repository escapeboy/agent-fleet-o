<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowEdgeAddTool extends Tool
{
    protected string $name = 'workflow_edge_add';

    protected string $description = 'Add an edge (connection) between two nodes in a workflow. Both nodes must belong to the same workflow.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID')
                ->required(),
            'source_node_id' => $schema->string()
                ->description('UUID of the source node (where the edge originates)')
                ->required(),
            'target_node_id' => $schema->string()
                ->description('UUID of the target node (where the edge points to)')
                ->required(),
            'label' => $schema->string()
                ->description('Optional display label for this edge'),
            'condition' => $schema->object()
                ->description('Condition object for conditional edges (e.g. {"field": "score", "op": "gt", "value": 0.8})'),
            'case_value' => $schema->string()
                ->description('Case value for switch node routing — this edge is taken when the expression equals this value'),
            'is_default' => $schema->boolean()
                ->description('Mark this as the default edge when no other condition matches. Default: false'),
            'source_channel' => $schema->string()
                ->description('Output port of the source node, e.g. "on_success", "on_error", "on_timeout"'),
            'target_channel' => $schema->string()
                ->description('Input slot of the target node (for multi-input nodes)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workflow_id' => 'required|string',
            'source_node_id' => 'required|string',
            'target_node_id' => 'required|string',
            'label' => 'nullable|string|max:100',
            'condition' => 'nullable|array',
            'case_value' => 'nullable|string',
            'is_default' => 'nullable|boolean',
            'source_channel' => 'nullable|string|max:100',
            'target_channel' => 'nullable|string|max:100',
        ]);

        $teamId = auth()->user()?->current_team_id;

        $workflow = Workflow::where('team_id', $teamId)->find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        // Validate both nodes exist and belong to this workflow
        $sourceNode = WorkflowNode::where('workflow_id', $workflow->id)->find($validated['source_node_id']);
        if (! $sourceNode) {
            return Response::error('Source node not found in this workflow.');
        }

        $targetNode = WorkflowNode::where('workflow_id', $workflow->id)->find($validated['target_node_id']);
        if (! $targetNode) {
            return Response::error('Target node not found in this workflow.');
        }

        if ($validated['source_node_id'] === $validated['target_node_id']) {
            return Response::error('Source and target nodes must be different.');
        }

        $edge = WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $validated['source_node_id'],
            'target_node_id' => $validated['target_node_id'],
            'label' => $validated['label'] ?? null,
            'condition' => $validated['condition'] ?? null,
            'case_value' => $validated['case_value'] ?? null,
            'is_default' => $validated['is_default'] ?? false,
            'source_channel' => $validated['source_channel'] ?? null,
            'target_channel' => $validated['target_channel'] ?? null,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'edge' => [
                'id' => $edge->id,
                'source_node_id' => $edge->source_node_id,
                'target_node_id' => $edge->target_node_id,
                'label' => $edge->label,
                'condition' => $edge->condition,
                'case_value' => $edge->case_value,
                'is_default' => $edge->is_default,
                'source_channel' => $edge->source_channel,
                'target_channel' => $edge->target_channel,
            ],
            'workflow_id' => $workflow->id,
            'source_label' => $sourceNode->label,
            'target_label' => $targetNode->label,
        ]));
    }
}
