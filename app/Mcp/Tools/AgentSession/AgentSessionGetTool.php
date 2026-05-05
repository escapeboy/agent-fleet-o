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
class AgentSessionGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_session_get';

    protected string $description = 'Fetch a single AgentSession with metadata and counts. Returns workspace_contract_snapshot and event statistics.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('AgentSession UUID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['session_id' => 'required|string']);

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

        return Response::json([
            'id' => $session->id,
            'team_id' => $session->team_id,
            'agent_id' => $session->agent_id,
            'experiment_id' => $session->experiment_id,
            'crew_execution_id' => $session->crew_execution_id,
            'user_id' => $session->user_id,
            'status' => $session->status?->value,
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'last_heartbeat_at' => $session->last_heartbeat_at?->toIso8601String(),
            'last_known_sandbox_id' => $session->last_known_sandbox_id,
            'workspace_contract_snapshot' => $session->workspace_contract_snapshot,
            'metadata' => $session->metadata,
            'event_count' => $session->events()->count(),
            'last_seq' => $session->lastSeq(),
        ]);
    }
}
