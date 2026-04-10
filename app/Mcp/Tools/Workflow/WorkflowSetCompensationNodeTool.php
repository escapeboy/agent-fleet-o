<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WorkflowSetCompensationNodeTool extends Tool
{
    protected string $name = 'workflow_set_compensation_node';

    protected string $description = 'Set or clear the compensation node for a workflow node (Saga pattern). The compensation node runs automatically when the workflow fails, in reverse completion order. Pass null to compensation_node_id to clear.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID')
                ->required(),
            'node_id' => $schema->string()
                ->description('The workflow node UUID to assign a compensation node to')
                ->required(),
            'compensation_node_id' => $schema->string()
                ->description('UUID of the node to run as compensation (must be in the same workflow). Pass null to clear.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workflow_id' => 'required|string',
            'node_id' => 'required|string',
            'compensation_node_id' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $workflow = Workflow::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        $node = WorkflowNode::where('workflow_id', $workflow->id)
            ->find($validated['node_id']);

        if (! $node) {
            return Response::error('Node not found in workflow.');
        }

        $compensationNodeId = $validated['compensation_node_id'] ?? null;

        if ($compensationNodeId !== null) {
            $compensationNode = WorkflowNode::where('workflow_id', $workflow->id)
                ->find($compensationNodeId);

            if (! $compensationNode) {
                return Response::error('Compensation node not found in the same workflow.');
            }

            if ($compensationNode->compensation_node_id !== null) {
                return Response::error('Compensation nodes cannot themselves have a compensation node (no recursive saga).');
            }

            if ($compensationNodeId === $validated['node_id']) {
                return Response::error('A node cannot be its own compensation node.');
            }
        }

        $node->update(['compensation_node_id' => $compensationNodeId]);

        return Response::text(json_encode([
            'success' => true,
            'node_id' => $node->id,
            'compensation_node_id' => $compensationNodeId,
            'message' => $compensationNodeId
                ? "Compensation node set. On workflow failure, '{$compensationNode->label}' will run to undo '{$node->label}'."
                : "Compensation node cleared for '{$node->label}'.",
        ]));
    }
}
