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
class AgentSessionListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_session_list';

    protected string $description = 'List AgentSessions for the current team. Optional filter by status (pending|active|sleeping|completed|cancelled|failed) and agent_id. Returns the most recent 50.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by AgentSessionStatus value')
                ->enum(['pending', 'active', 'sleeping', 'completed', 'cancelled', 'failed']),
            'agent_id' => $schema->string()
                ->description('Filter by agent UUID'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'status' => 'nullable|string',
            'agent_id' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $query = AgentSession::withoutGlobalScopes()->where('team_id', $teamId);
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['agent_id'])) {
            $query->where('agent_id', $validated['agent_id']);
        }

        $rows = $query->latest('created_at')->take(50)->get([
            'id', 'agent_id', 'experiment_id', 'crew_execution_id', 'status',
            'started_at', 'ended_at', 'last_heartbeat_at', 'last_known_sandbox_id', 'created_at',
        ]);

        return Response::json([
            'sessions' => $rows->map(fn ($s) => [
                'id' => $s->id,
                'agent_id' => $s->agent_id,
                'experiment_id' => $s->experiment_id,
                'crew_execution_id' => $s->crew_execution_id,
                'status' => $s->status?->value,
                'started_at' => $s->started_at?->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
                'last_heartbeat_at' => $s->last_heartbeat_at?->toIso8601String(),
                'last_known_sandbox_id' => $s->last_known_sandbox_id,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
