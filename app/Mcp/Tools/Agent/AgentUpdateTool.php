<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AgentUpdateTool extends Tool
{
    protected string $name = 'agent_update';

    protected string $description = 'Update an existing AI agent. Only provided fields will be changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New agent name'),
            'role' => $schema->string()
                ->description('New role description'),
            'goal' => $schema->string()
                ->description('New goal'),
            'backstory' => $schema->string()
                ->description('New backstory'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'role' => 'nullable|string',
            'goal' => 'nullable|string',
            'backstory' => 'nullable|string',
        ]);

        $agent = Agent::find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $data = array_filter([
            'name' => $validated['name'] ?? null,
            'role' => $validated['role'] ?? null,
            'goal' => $validated['goal'] ?? null,
            'backstory' => $validated['backstory'] ?? null,
        ], fn ($v) => $v !== null);

        if (empty($data)) {
            return Response::error('No fields to update. Provide at least one of: name, role, goal, backstory.');
        }

        $agent->update($data);

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'updated_fields' => array_keys($data),
        ]));
    }
}
