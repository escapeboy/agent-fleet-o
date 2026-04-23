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

#[AssistantTool('write')]
class AgentChatSendTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_chat_send';

    protected string $description = 'Send a natural-language chat_message to a registered remote agent via the Agent Chat Protocol. Returns the remote response when synchronous, or a session id for later retrieval.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'external_agent_id' => $schema->string()->description('External agent UUID')->required(),
            'content' => $schema->string()->description('Message content')->required(),
            'session_token' => $schema->string()->description('Optional session token to continue an existing conversation'),
            'from' => $schema->string()->description('Optional sender identifier override'),
        ];
    }

    public function handle(Request $request, DispatchChatMessageAction $action): Response
    {
        $validated = $request->validate([
            'external_agent_id' => 'required|string',
            'content' => 'required|string',
            'session_token' => 'sometimes|string|max:128',
            'from' => 'sometimes|string|max:255',
        ]);

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
                content: $validated['content'],
                sessionToken: $validated['session_token'] ?? null,
                from: $validated['from'] ?? null,
            );
        } catch (\Throwable $e) {
            return $this->upstreamError($e->getMessage());
        }

        return Response::text(json_encode($result));
    }
}
