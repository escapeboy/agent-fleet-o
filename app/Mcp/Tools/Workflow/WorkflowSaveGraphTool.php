<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WorkflowSaveGraphTool extends Tool
{
    protected string $name = 'workflow_save_graph';

    protected string $description = 'Save the node/edge graph for a workflow. Replaces existing nodes and edges with the provided ones. Each node must have type (start/end/agent/conditional) and label.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID')
                ->required(),
            'nodes' => $schema->array()
                ->description('Array of node objects. Each must have: type (start|end|agent|conditional|human_task|switch|dynamic_fork|do_while), label (string), and optionally agent_id, skill_id, config.')
                ->items($schema->object())
                ->required(),
            'edges' => $schema->array()
                ->description('Array of edge objects. Each must have: source_node_index (int), target_node_index (int), and optionally condition, label, is_default.')
                ->items($schema->object()),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workflow_id' => 'required|string',
            'nodes' => 'required|array',
            'edges' => 'sometimes|array',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $workflow = Workflow::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        try {
            $updated = app(UpdateWorkflowAction::class)->execute(
                workflow: $workflow,
                nodes: $validated['nodes'],
                edges: $validated['edges'] ?? [],
            );

            $updated->load(['nodes', 'edges']);

            return Response::text(json_encode([
                'success' => true,
                'id' => $updated->id,
                'name' => $updated->name,
                'status' => $updated->status->value,
                'node_count' => $updated->nodes->count(),
                'edge_count' => $updated->edges->count(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
