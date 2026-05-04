<?php

namespace App\Mcp\Tools\AgentSession;

use App\Domain\AgentSession\Actions\CancelAgentSessionAction;
use App\Domain\AgentSession\Models\AgentSession;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentSessionCancelTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_session_cancel';

    protected string $description = 'Cancel an agent session. Status moves to Cancelled and ended_at is stamped. Idempotent — calling on an already-terminal session returns the same row without emitting a duplicate event.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('AgentSession UUID')->required(),
            'reason' => $schema->string()->description('Optional reason recorded on the cancel event'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'reason' => 'nullable|string|max:255',
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

        $session = app(CancelAgentSessionAction::class)
            ->execute($session, $validated['reason'] ?? null);

        return Response::json([
            'id' => $session->id,
            'status' => $session->status->value,
            'ended_at' => $session->ended_at?->toIso8601String(),
        ]);
    }
}
