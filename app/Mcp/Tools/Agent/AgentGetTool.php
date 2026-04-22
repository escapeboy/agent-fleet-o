<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class AgentGetTool extends Tool
{
    use HasStructuredErrors;

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

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);

        if (! $agent) {
            return $this->notFoundError('agent', $validated['agent_id']);
        }

        $state = $agent->runtimeState;

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
            'scope' => $agent->scope?->value,
            'owner_user_id' => $agent->owner_user_id,
            'created' => $agent->created_at?->toIso8601String(),
            'runtime_state' => $state ? [
                'total_executions' => $state->total_executions,
                'total_input_tokens' => $state->total_input_tokens,
                'total_output_tokens' => $state->total_output_tokens,
                'total_cost_credits' => $state->total_cost_credits,
                'last_active_at' => $state->last_active_at?->toIso8601String(),
                'last_error' => $state->last_error,
            ] : null,
        ]));
    }
}
