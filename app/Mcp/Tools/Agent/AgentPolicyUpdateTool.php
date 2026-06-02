<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\UpdateAgentPolicyAction;
use App\Domain\Agent\Models\AgentPolicy;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentPolicyUpdateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_policy_update';

    protected string $description = 'Update an agent policy. Passing rules mints a NEW immutable version (history preserved); name/enabled change in place. Toggle enabled to activate/deactivate governance for this scope.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'policy_id' => $schema->string()->required()->description('The agent policy UUID'),
            'rules' => $schema->string()->description('JSON object of policy rules — creates a new version'),
            'name' => $schema->string()->description('New name'),
            'enabled' => $schema->boolean()->description('Activate/deactivate this policy'),
            'notes' => $schema->string()->description('Optional note for the new version'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'policy_id' => 'required|string',
            'rules' => 'nullable|string',
            'name' => 'nullable|string|max:200',
            'enabled' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $policy = AgentPolicy::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['policy_id']);

        if (! $policy) {
            return $this->notFoundError('agent policy');
        }

        $rules = null;
        if (! empty($validated['rules'])) {
            $decoded = json_decode($validated['rules'], true);
            if (! is_array($decoded)) {
                return $this->invalidArgumentError('rules must be a valid JSON object.');
            }
            $rules = $decoded;
        }

        $policy = app(UpdateAgentPolicyAction::class)->execute(
            policy: $policy,
            rules: $rules,
            name: $validated['name'] ?? null,
            enabled: array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : null,
            createdBy: auth()->id(),
            notes: $validated['notes'] ?? null,
        );

        return Response::text(json_encode([
            'success' => true,
            'id' => $policy->id,
            'current_version_id' => $policy->current_version_id,
            'enabled' => $policy->enabled,
        ]));
    }
}
