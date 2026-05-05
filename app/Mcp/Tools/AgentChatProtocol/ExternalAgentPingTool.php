<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Actions\DispatchChatMessageAction;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('read')]
class ExternalAgentPingTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'external_agent_ping';

    protected string $description = 'Send a minimal "ping" chat message to a remote agent to verify reachability, auth, and protocol compliance.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'external_agent_id' => $schema->string()->description('External agent UUID')->required(),
        ];
    }

    public function handle(Request $request, DispatchChatMessageAction $action): Response
    {
        $validated = $request->validate(['external_agent_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = ExternalAgent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['external_agent_id']);
        if (! $agent) {
            return $this->notFoundError('external_agent', $validated['external_agent_id']);
        }

        try {
            $result = $action->execute(
                externalAgent: $agent,
                content: 'ping',
                from: 'fleetq:team:'.$teamId.':ping',
            );

            return Response::text(json_encode([
                'ok' => true,
                'session_id' => $result['session_id'],
                'latency_recorded' => true,
            ]));
        } catch (\Throwable $e) {
            return Response::text(json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ]));
        }
    }
}
