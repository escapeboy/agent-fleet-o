<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\LockToolResourceAction;
use App\Domain\Agent\Enums\ToolLockoutMatchMode;
use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentToolLockoutSetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_tool_lockout_set';

    protected string $description = 'Lock a tool resource for review (reviewer-lockout). While active, an agent is blocked from mutating tool calls (bash_execute/file_write/ssh_execute) whose target matches the resource — the same agent cannot re-touch a rejected artifact until released. Requires AGENT_TOOL_GOVERNANCE_ENABLED=true to take effect.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->required()->description('The resource to lock — a file path, command, or tool name (e.g. "src/auth.php" or "bash_execute").'),
            'match_mode' => $schema->string()->description('How resource is matched against the call target: equals (default), contains, or prefix.')->enum(['equals', 'contains', 'prefix']),
            'agent_id' => $schema->string()->description('Lock for a specific agent. Omit for a team-wide lockout (applies to every agent).'),
            'reason' => $schema->string()->description('Why this resource is locked (shown to the blocked agent).'),
        ];
    }

    public function handle(Request $request, LockToolResourceAction $action): Response
    {
        $validated = $request->validate([
            'resource' => 'required|string',
            'match_mode' => 'nullable|string|in:equals,contains,prefix',
            'agent_id' => 'nullable|string',
            'reason' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        // IDOR guard: an agent_id, when given, must belong to the team.
        $agentId = $validated['agent_id'] ?? null;
        if ($agentId !== null && $agentId !== '') {
            $exists = Agent::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('id', $agentId)
                ->exists();
            if (! $exists) {
                return $this->notFoundError('agent');
            }
        } else {
            $agentId = null;
        }

        $lockout = $action->execute(
            teamId: (string) $teamId,
            resource: $validated['resource'],
            agentId: $agentId,
            matchMode: ToolLockoutMatchMode::from($validated['match_mode'] ?? 'equals'),
            reason: $validated['reason'] ?? null,
            lockedBy: auth()->id(),
        );

        return Response::text(json_encode([
            'success' => true,
            'lockout_id' => $lockout->id,
            'resource' => $lockout->resource,
            'match_mode' => $lockout->match_mode->value,
            'scope' => $lockout->agent_id === null ? 'team_wide' : 'agent',
        ]));
    }
}
