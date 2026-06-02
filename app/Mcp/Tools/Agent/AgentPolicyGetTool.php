<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
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
class AgentPolicyGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_policy_get';

    protected string $description = 'Get one agent policy with its current rules and full version history.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'policy_id' => $schema->string()->required()->description('The agent policy UUID'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['policy_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $policy = AgentPolicy::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->with('currentVersion')
            ->find($validated['policy_id']);

        if (! $policy) {
            return $this->notFoundError('agent policy');
        }

        $versions = AgentPolicyVersion::withoutGlobalScopes()
            ->where('agent_policy_id', $policy->id)
            ->orderBy('version')
            ->get()
            ->map(fn (AgentPolicyVersion $v) => [
                'id' => $v->id,
                'version' => $v->version,
                'rules' => $v->rules,
                'notes' => $v->notes,
                'rolled_back_from_version_id' => $v->rolled_back_from_version_id,
                'created_at' => $v->created_at?->toIso8601String(),
            ]);

        return Response::text(json_encode([
            'id' => $policy->id,
            'name' => $policy->name,
            'agent_id' => $policy->agent_id,
            'status' => $policy->status->value,
            'enabled' => $policy->enabled,
            'current_version_id' => $policy->current_version_id,
            'current_rules' => $policy->currentVersion?->rules,
            'versions' => $versions,
        ]));
    }
}
