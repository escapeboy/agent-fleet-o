<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SaveWorkflowGraphTool implements Tool
{
    public function name(): string
    {
        return 'save_workflow_graph';
    }

    public function description(): string
    {
        return 'Save or replace the node/edge graph for an existing workflow. Use after create_workflow to add nodes and connections, or to fix a generated workflow. Nodes JSON: [{type,label,agent_id?,position_x?,position_y?,config?}]. Edges JSON: [{source_node_index,target_node_index,condition?,is_default?}]. Node types: start, end, agent, conditional, human_task, switch.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()->required()->description('UUID of the workflow to update'),
            'nodes' => $schema->string()->required()->description('JSON array of node objects. Example: [{"type":"start","label":"Start"},{"type":"agent","label":"Researcher","agent_id":"<uuid>"},{"type":"end","label":"End"}]'),
            'edges' => $schema->string()->required()->description('JSON array of edge objects using 0-based node indices. Example: [{"source_node_index":0,"target_node_index":1},{"source_node_index":1,"target_node_index":2}]'),
        ];
    }

    public function handle(Request $request): string
    {
        $workflow = Workflow::find($request->get('workflow_id'));
        if (! $workflow) {
            return json_encode(['error' => "Workflow not found: {$request->get('workflow_id')}"]);
        }

        $nodesArray = json_decode($request->get('nodes'), true);
        $edgesArray = json_decode($request->get('edges'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return json_encode(['error' => 'Invalid JSON: '.json_last_error_msg()]);
        }

        $remappedEdges = array_map(fn ($e) => array_merge($e, [
            'source_node_id' => $e['source_node_index'] ?? $e['source_node_id'] ?? null,
            'target_node_id' => $e['target_node_index'] ?? $e['target_node_id'] ?? null,
        ]), $edgesArray ?? []);

        try {
            $updated = app(UpdateWorkflowAction::class)->execute(
                workflow: $workflow,
                nodes: $nodesArray ?? [],
                edges: $remappedEdges,
            );

            $updated->load(['nodes', 'edges']);

            return json_encode([
                'success' => true,
                'workflow_id' => $updated->id,
                'name' => $updated->name,
                'node_count' => $updated->nodes->count(),
                'edge_count' => $updated->edges->count(),
                'status' => $updated->status->value,
                'url' => route('workflows.show', $updated),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
