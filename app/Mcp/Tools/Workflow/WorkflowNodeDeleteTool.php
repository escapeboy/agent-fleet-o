<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\WorkflowNode;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WorkflowNodeDeleteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_node_delete';

    protected string $description = 'Delete a workflow node. All edges connected to this node are automatically removed. Cannot delete start or end nodes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->string()
                ->description('The workflow node UUID to delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['node_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $node = WorkflowNode::whereHas('workflow', fn ($q) => $q->where('team_id', $teamId))
            ->find($validated['node_id']);

        if (! $node) {
            return $this->notFoundError('node');
        }

        if ($node->isStart() || $node->isEnd()) {
            return $this->failedPreconditionError('Cannot delete start or end nodes. Use workflow_save_graph to replace the entire graph if needed.');
        }

        $workflowId = $node->workflow_id;
        $label = $node->label;

        $node->delete();

        return Response::text(json_encode([
            'success' => true,
            'deleted_node_id' => $validated['node_id'],
            'label' => $label,
            'workflow_id' => $workflowId,
        ]));
    }
}
