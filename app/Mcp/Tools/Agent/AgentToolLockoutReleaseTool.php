<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\ReleaseToolLockoutAction;
use App\Domain\Agent\Models\AgentToolLockout;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentToolLockoutReleaseTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_tool_lockout_release';

    protected string $description = 'Release a reviewer-lockout once the locked resource has been reviewed and cleared — the agent may touch it again.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'lockout_id' => $schema->string()->required()->description('The AgentToolLockout UUID to release.'),
        ];
    }

    public function handle(Request $request, ReleaseToolLockoutAction $action): Response
    {
        $validated = $request->validate(['lockout_id' => 'required|string']);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $lockout = AgentToolLockout::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['lockout_id']);

        if (! $lockout) {
            return $this->notFoundError('lockout');
        }

        $lockout = $action->execute($lockout);

        return Response::text(json_encode([
            'success' => true,
            'lockout_id' => $lockout->id,
            'released_at' => $lockout->released_at?->toIso8601String(),
        ]));
    }
}
