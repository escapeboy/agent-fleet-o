<?php

namespace App\Mcp\Tools\AgentSession;

use App\Domain\AgentSession\Models\AgentSession;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class AgentSessionEventsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_session_events';

    protected string $description = 'Read the AgentSession event log. Supports cursor pagination via from_seq + limit (default 100, max 500). Events are ordered by seq ascending.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('AgentSession UUID')->required(),
            'from_seq' => $schema->integer()->description('Return events with seq >= from_seq (default 1)'),
            'limit' => $schema->integer()->description('Max events (default 100, max 500)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'from_seq' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $session = AgentSession::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['session_id']);
        if (! $session) {
            return $this->notFoundError('agent_session');
        }

        $fromSeq = (int) ($validated['from_seq'] ?? 1);
        $limit = (int) ($validated['limit'] ?? 100);

        $events = $session->events()
            ->where('seq', '>=', $fromSeq)
            ->orderBy('seq')
            ->take($limit)
            ->get(['seq', 'kind', 'payload', 'created_at']);

        return Response::json([
            'session_id' => $session->id,
            'from_seq' => $fromSeq,
            'count' => $events->count(),
            'next_seq' => $events->isNotEmpty() ? ((int) $events->last()->seq + 1) : $fromSeq,
            'events' => $events->map(fn ($e) => [
                'seq' => (int) $e->seq,
                'kind' => $e->kind?->value,
                'payload' => $e->payload,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
