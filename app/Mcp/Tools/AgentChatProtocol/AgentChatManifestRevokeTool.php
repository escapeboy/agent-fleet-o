<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Actions\RevokeAgentManifestAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class AgentChatManifestRevokeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_chat_manifest_revoke';

    protected string $description = 'Disable the Agent Chat Protocol on an agent. Public manifest is removed. Active inbound calls start returning 404.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Agent UUID')->required(),
        ];
    }

    public function handle(Request $request, RevokeAgentManifestAction $action): Response
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

        $agent = $action->execute($agent);

        return Response::text(json_encode([
            'agent_id' => $agent->id,
            'chat_protocol_enabled' => false,
        ]));
    }
}
