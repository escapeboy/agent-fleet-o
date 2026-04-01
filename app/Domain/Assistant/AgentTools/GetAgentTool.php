<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetAgentTool implements Tool
{
    public function name(): string
    {
        return 'get_agent';
    }

    public function description(): string
    {
        return 'Get detailed information about a specific AI agent';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->required()->description('The agent UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $agent = Agent::find($request->get('agent_id'));
        if (! $agent) {
            return json_encode(['error' => 'Agent not found']);
        }

        return json_encode([
            'id' => $agent->id,
            'name' => $agent->name,
            'role' => $agent->role,
            'goal' => $agent->goal,
            'backstory' => $agent->backstory,
            'provider' => $agent->provider,
            'model' => $agent->model,
            'status' => $agent->status->value,
            'budget_spent' => $agent->budget_spent_credits,
            'budget_cap' => $agent->budget_cap_credits,
            'url' => route('agents.show', $agent),
        ]);
    }
}
