<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

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
class AgentChatSessionsListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_chat_sessions_list';

    protected string $description = 'List recent Agent Chat Protocol sessions (inbound + outbound). Ordered by last activity.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Max results (default 25, max 100)'),
            'agent_id' => $schema->string()->description('Filter by internal agent UUID'),
            'external_agent_id' => $schema->string()->description('Filter by external agent UUID'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:100',
            'agent_id' => 'sometimes|string',
            'external_agent_id' => 'sometimes|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $query = AgentChatSession::withoutGlobalScopes()->where('team_id', $teamId);
        if (isset($validated['agent_id'])) {
            $query->where('agent_id', $validated['agent_id']);
        }
        if (isset($validated['external_agent_id'])) {
            $query->where('external_agent_id', $validated['external_agent_id']);
        }

        $sessions = $query->orderByDesc('last_activity_at')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get();

        return Response::text(json_encode([
            'sessions' => $sessions->map(fn (AgentChatSession $s) => [
                'id' => $s->id,
                'session_token' => $s->session_token,
                'agent_id' => $s->agent_id,
                'external_agent_id' => $s->external_agent_id,
                'message_count' => $s->message_count,
                'last_activity_at' => $s->last_activity_at->toIso8601String(),
            ])->toArray(),
            'count' => $sessions->count(),
        ]));
    }
}
