<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DeleteAgentTool implements Tool
{
    public function name(): string
    {
        return 'delete_agent';
    }

    public function description(): string
    {
        return 'Soft-delete an AI agent. The agent must not have active experiments. This is a destructive action.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->required()->description('The agent UUID to delete'),
        ];
    }

    public function handle(Request $request): string
    {
        $agent = Agent::find($request->get('agent_id'));
        if (! $agent) {
            return json_encode(['error' => 'Agent not found']);
        }

        try {
            $agentName = $agent->name;
            $agent->delete();

            return json_encode([
                'success' => true,
                'agent_id' => $request->get('agent_id'),
                'name' => $agentName,
                'message' => "Agent '{$agentName}' has been deleted.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
