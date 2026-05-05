<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use App\Domain\AgentChatProtocol\Models\AgentChatSession;
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
class AgentChatSessionGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_chat_session_get';

    protected string $description = 'Get a session and its full message thread (inbound + outbound, ordered chronologically).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('AgentChatSession UUID')->required(),
            'message_limit' => $schema->integer()->description('Max messages to return (default 100, max 500)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'message_limit' => 'sometimes|integer|min:1|max:500',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $session = AgentChatSession::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['session_id']);
        if (! $session) {
            return $this->notFoundError('agent_chat_session', $validated['session_id']);
        }

        $messages = AgentChatMessage::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('session_id', $session->id)
            ->orderBy('created_at')
            ->limit((int) ($validated['message_limit'] ?? 100))
            ->get();

        return Response::text(json_encode([
            'session' => [
                'id' => $session->id,
                'session_token' => $session->session_token,
                'agent_id' => $session->agent_id,
                'external_agent_id' => $session->external_agent_id,
                'message_count' => $session->message_count,
                'last_activity_at' => $session->last_activity_at->toIso8601String(),
            ],
            'messages' => $messages->map(fn (AgentChatMessage $m) => [
                'id' => $m->id,
                'msg_id' => $m->msg_id,
                'direction' => $m->direction->value,
                'message_type' => $m->message_type->value,
                'from' => $m->from_identifier,
                'to' => $m->to_identifier,
                'status' => $m->status->value,
                'payload' => $m->payload,
                'created_at' => $m->created_at->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
