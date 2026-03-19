<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\WorkflowEdge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WorkflowEdgeDeleteTool extends Tool
{
    protected string $name = 'workflow_edge_delete';

    protected string $description = 'Delete an edge (connection) between two workflow nodes. The nodes themselves remain intact.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'edge_id' => $schema->string()
                ->description('The workflow edge UUID to delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['edge_id' => 'required|string']);

        $teamId = auth()->user()?->current_team_id;

        $edge = WorkflowEdge::whereHas('workflow', fn ($q) => $q->where('team_id', $teamId))
            ->find($validated['edge_id']);

        if (! $edge) {
            return Response::error('Edge not found.');
        }

        $workflowId = $edge->workflow_id;
        $sourceNodeId = $edge->source_node_id;
        $targetNodeId = $edge->target_node_id;

        $edge->delete();

        return Response::text(json_encode([
            'success' => true,
            'deleted_edge_id' => $validated['edge_id'],
            'source_node_id' => $sourceNodeId,
            'target_node_id' => $targetNodeId,
            'workflow_id' => $workflowId,
        ]));
    }
}
