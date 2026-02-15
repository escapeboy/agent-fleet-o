<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AgentGetTool extends Tool
{
    protected string $name = 'agent_get';

    protected string $description = 'Get detailed information about a specific AI agent including role, goal, backstory, provider, model, and budget.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['agent_id' => 'required|string']);

        $agent = Agent::find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        return Response::text(json_encode([
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
            'created' => $agent->created_at?->toIso8601String(),
        ]));
    }
}
