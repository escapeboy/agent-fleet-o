<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowNodeAddTool extends Tool
{
    protected string $name = 'workflow_node_add';

    protected string $description = 'Add a new node to an existing workflow. The node is appended after existing nodes. Use workflow_edge_add to connect it to other nodes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID to add the node to')
                ->required(),
            'type' => $schema->string()
                ->description('Node type: agent (executes an agent), conditional (branches on expression), human_task (waits for human), switch (multi-way branch), dynamic_fork (parallel split), do_while (loop)')
                ->enum(['agent', 'conditional', 'human_task', 'switch', 'dynamic_fork', 'do_while'])
                ->required(),
            'label' => $schema->string()
                ->description('Human-readable label for this node')
                ->required(),
            'agent_id' => $schema->string()
                ->description('UUID of the agent to assign (for agent nodes)'),
            'skill_id' => $schema->string()
                ->description('UUID of the skill to assign'),
            'crew_id' => $schema->string()
                ->description('UUID of the crew to assign (for crew nodes)'),
            'config' => $schema->object()
                ->description('Node configuration (e.g. {"timeout": 300, "retries": 2})'),
            'expression' => $schema->string()
                ->description('Condition expression for conditional/switch nodes'),
            'position_x' => $schema->integer()
                ->description('Horizontal canvas position')
                ->default(0),
            'position_y' => $schema->integer()
                ->description('Vertical canvas position')
                ->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workflow_id' => 'required|string',
            'type' => 'required|string|in:agent,conditional,human_task,switch,dynamic_fork,do_while',
            'label' => 'required|string|max:255',
            'agent_id' => 'nullable|uuid|exists:agents,id',
            'skill_id' => 'nullable|uuid|exists:skills,id',
            'crew_id' => 'nullable|uuid|exists:crews,id',
            'config' => 'nullable|array',
            'expression' => 'nullable|string|max:500',
            'position_x' => 'nullable|integer',
            'position_y' => 'nullable|integer',
        ]);

        $teamId = auth()->user()?->current_team_id;

        $workflow = Workflow::where('team_id', $teamId)->find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        $maxOrder = $workflow->nodes()->max('order') ?? -1;

        $node = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => $validated['type'],
            'label' => $validated['label'],
            'agent_id' => $validated['agent_id'] ?? null,
            'skill_id' => $validated['skill_id'] ?? null,
            'crew_id' => $validated['crew_id'] ?? null,
            'config' => $validated['config'] ?? [],
            'expression' => $validated['expression'] ?? null,
            'position_x' => $validated['position_x'] ?? 0,
            'position_y' => $validated['position_y'] ?? 0,
            'order' => $maxOrder + 1,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'node' => [
                'id' => $node->id,
                'type' => $node->type->value,
                'label' => $node->label,
                'agent_id' => $node->agent_id,
                'skill_id' => $node->skill_id,
                'crew_id' => $node->crew_id,
                'config' => $node->config,
                'expression' => $node->expression,
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
                'order' => $node->order,
            ],
            'workflow_id' => $workflow->id,
            'total_nodes' => $workflow->nodes()->count(),
        ]));
    }
}
