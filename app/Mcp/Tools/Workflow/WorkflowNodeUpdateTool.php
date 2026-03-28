<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowNodeUpdateTool extends Tool
{
    protected string $name = 'workflow_node_update';

    protected string $description = 'Update a specific workflow node — assign an agent, change the label, update config, or reposition. Only provided fields are changed; others are left as-is.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->string()
                ->description('The workflow node UUID')
                ->required(),
            'label' => $schema->string()
                ->description('New label for this node'),
            'agent_id' => $schema->string()
                ->description('UUID of the agent to assign to this node. Pass empty string to detach.'),
            'skill_id' => $schema->string()
                ->description('UUID of the skill to assign to this node. Pass empty string to detach.'),
            'crew_id' => $schema->string()
                ->description('UUID of the crew to assign to this node. Pass empty string to detach.'),
            'config' => $schema->object()
                ->description('Node configuration object (e.g. timeout, retries, prompt_override)'),
            'expression' => $schema->string()
                ->description('Condition expression for conditional/switch nodes (e.g. "output.score > 0.8")'),
            'position_x' => $schema->integer()
                ->description('Horizontal position on the canvas'),
            'position_y' => $schema->integer()
                ->description('Vertical position on the canvas'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'node_id' => 'required|string',
            'label' => 'nullable|string|max:255',
            'agent_id' => 'nullable|string',
            'skill_id' => 'nullable|string',
            'crew_id' => 'nullable|string',
            'config' => 'nullable|array',
            'expression' => 'nullable|string|max:500',
            'position_x' => 'nullable|integer',
            'position_y' => 'nullable|integer',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $node = WorkflowNode::whereHas('workflow', fn ($q) => $q->where('team_id', $teamId))
            ->find($validated['node_id']);

        if (! $node) {
            return Response::error('Node not found.');
        }

        $updateData = [];

        if (isset($validated['label'])) {
            $updateData['label'] = $validated['label'];
        }
        if (array_key_exists('agent_id', $validated)) {
            $updateData['agent_id'] = $validated['agent_id'] ?: null;
        }
        if (array_key_exists('skill_id', $validated)) {
            $updateData['skill_id'] = $validated['skill_id'] ?: null;
        }
        if (array_key_exists('crew_id', $validated)) {
            $updateData['crew_id'] = $validated['crew_id'] ?: null;
        }
        if (isset($validated['config'])) {
            $updateData['config'] = $validated['config'];
        }
        if (array_key_exists('expression', $validated)) {
            $updateData['expression'] = $validated['expression'];
        }
        if (isset($validated['position_x'])) {
            $updateData['position_x'] = $validated['position_x'];
        }
        if (isset($validated['position_y'])) {
            $updateData['position_y'] = $validated['position_y'];
        }

        if (empty($updateData)) {
            return Response::error('No fields to update.');
        }

        $node->update($updateData);
        $node->refresh();

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
            'updated_fields' => array_keys($updateData),
        ]));
    }
}
