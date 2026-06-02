<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\RollbackAgentPolicyAction;
use App\Domain\Agent\Models\AgentPolicy;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentPolicyRollbackTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_policy_rollback';

    protected string $description = 'Roll a policy back to a prior version. Mints a new version copying the target rules and re-points current — the 30-second undo for an over-permissive change.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'policy_id' => $schema->string()->required()->description('The agent policy UUID'),
            'version_id' => $schema->string()->required()->description('The AgentPolicyVersion UUID to roll back to'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'policy_id' => 'required|string',
            'version_id' => 'required|string',
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

        try {
            $policy = app(RollbackAgentPolicyAction::class)->execute(
                $policy,
                $validated['version_id'],
                auth()->id(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        }

        return Response::text(json_encode([
            'success' => true,
            'id' => $policy->id,
            'current_version_id' => $policy->current_version_id,
            'current_version' => $policy->currentVersion?->version,
        ]));
    }
}
