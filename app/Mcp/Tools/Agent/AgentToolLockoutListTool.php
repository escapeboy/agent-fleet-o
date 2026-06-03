<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AgentToolLockout;
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
class AgentToolLockoutListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_tool_lockout_list';

    protected string $description = 'List reviewer-lockouts for the team. By default only active (unreleased) lockouts are returned.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Filter to lockouts for a specific agent (team-wide lockouts are always included).'),
            'include_released' => $schema->boolean()->description('Include released lockouts in the result (default false).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $query = AgentToolLockout::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->latest('created_at');

        if ($request->get('include_released') !== true) {
            $query->whereNull('released_at');
        }

        $agentId = $request->get('agent_id');
        if (is_string($agentId) && $agentId !== '') {
            $query->where(function ($q) use ($agentId) {
                $q->whereNull('agent_id')->orWhere('agent_id', $agentId);
            });
        }

        $lockouts = $query->get()->map(fn (AgentToolLockout $l) => [
            'id' => $l->id,
            'resource' => $l->resource,
            'match_mode' => $l->match_mode->value,
            'scope' => $l->agent_id === null ? 'team_wide' : 'agent',
            'agent_id' => $l->agent_id,
            'reason' => $l->reason,
            'active' => $l->isActive(),
            'released_at' => $l->released_at?->toIso8601String(),
            'created_at' => $l->created_at?->toIso8601String(),
        ]);

        return Response::text(json_encode([
            'count' => $lockouts->count(),
            'lockouts' => $lockouts,
        ]));
    }
}
