<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentRuntimeState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class AgentResetSessionTool extends Tool
{
    protected string $name = 'agent_reset_session';

    protected string $description = 'Reset the runtime session of an agent — clears session_id so the next execution starts fresh. Use when an agent is stuck in an old session context.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('Agent UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return Response::error('Authentication required.');
        }

        $validated = $request->validate(['agent_id' => 'required|string']);

        $agent = Agent::withoutGlobalScopes()
            ->where('id', $validated['agent_id'])
            ->where('team_id', $teamId)
            ->first();

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $state = AgentRuntimeState::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->where('team_id', $teamId)
            ->first();

        if ($state) {
            $state->update(['session_id' => null]);
        }

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'message' => 'Session cleared.',
        ]));
    }
}
