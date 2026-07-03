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
class AgentEquivocationStatusTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_equivocation_status';

    protected string $description = 'Get the equivocation status of an agent. Equivocation occurs when an agent returns different responses to the same prompt. At 3+ incidents the agent is auto-degraded.';

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
        $validated = $request->validate([
            'agent_id' => 'required|string',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['agent_id']);

        if (! $agent) {
            return $this->notFoundError('agent');
        }

        return Response::text(json_encode([
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'status' => $agent->status->value,
            'equivocation_count' => $agent->equivocation_count ?? 0,
            'last_equivocated_at' => $agent->last_equivocated_at?->toIso8601String(),
            'degraded' => $agent->status->value === 'degraded',
        ]));
    }
}
