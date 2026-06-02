<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\CreateAgentPolicyAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentPolicyCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_policy_create';

    protected string $description = 'Create a versioned agent policy. Omit agent_id for the team-default policy. rules is a JSON object: {allowed_target_types, denied_target_types, risk_ceiling, auto_execute:{enabled,threshold}, spend_cap:{credits,window}, frequency_cap:{count,window}, sensitive_paths}. Ships disabled — set enabled=true to activate.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Human-readable policy name'),
            'agent_id' => $schema->string()->description('Scope to one agent (omit = team default)'),
            'rules' => $schema->string()->description('JSON object of policy rules (merged over the safe defaults)'),
            'enabled' => $schema->boolean()->description('Activate immediately (default false)'),
            'notes' => $schema->string()->description('Optional note for the first version'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'agent_id' => 'nullable|string',
            'rules' => 'nullable|string',
            'enabled' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $rules = [];
        if (! empty($validated['rules'])) {
            $decoded = json_decode($validated['rules'], true);
            if (! is_array($decoded)) {
                return $this->invalidArgumentError('rules must be a valid JSON object.');
            }
            $rules = $decoded;
        }

        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $teamId,
            name: $validated['name'],
            agentId: $validated['agent_id'] ?? null,
            rules: $rules,
            enabled: (bool) ($validated['enabled'] ?? false),
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
