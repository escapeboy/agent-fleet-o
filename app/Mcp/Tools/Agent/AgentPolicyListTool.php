<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AgentPolicy;
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
class AgentPolicyListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_policy_list';

    protected string $description = 'List versioned agent policies (team-default + agent-specific) governing autonomous action routing.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Filter to a specific agent (omit for all, including the team default).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $query = AgentPolicy::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->with('currentVersion')
            ->latest('updated_at');

        if (is_string($request->get('agent_id'))) {
            $query->where('agent_id', $request->get('agent_id'));
        }

        $policies = $query->get()->map(fn (AgentPolicy $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'agent_id' => $p->agent_id,
            'scope' => $p->agent_id === null ? 'team_default' : 'agent',
            'status' => $p->status->value,
            'enabled' => $p->enabled,
            'current_version' => $p->currentVersion?->version,
            'rules' => $p->currentVersion?->rules,
        ]);

        return Response::text(json_encode(['policies' => $policies]));
    }
}
