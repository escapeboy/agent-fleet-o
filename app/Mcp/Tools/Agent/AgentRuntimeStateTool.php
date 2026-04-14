<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentRuntimeState;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class AgentRuntimeStateTool extends Tool
{
    protected string $name = 'agent_runtime_state_get';

    protected string $description = 'Get the persistent runtime state for an agent, including lifetime token usage, total cost, execution count, and last session ID.';

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

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $state = AgentRuntimeState::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->first();

        if (! $state) {
            return Response::text(json_encode([
                'agent_id' => $agent->id,
                'state' => null,
                'note' => 'Runtime state not yet seeded for this agent.',
            ]));
        }

        return Response::text(json_encode([
            'agent_id' => $agent->id,
            'session_id' => $state->session_id,
            'total_executions' => $state->total_executions,
            'total_input_tokens' => $state->total_input_tokens,
            'total_output_tokens' => $state->total_output_tokens,
            'total_cost_credits' => $state->total_cost_credits,
            'last_error' => $state->last_error,
            'last_active_at' => $state->last_active_at?->toIso8601String(),
            'state_json' => $state->state_json,
        ]));
    }
}
