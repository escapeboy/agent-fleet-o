<?php

namespace App\Mcp\Tools\AgentSession;

use App\Domain\AgentSession\Actions\ForkAgentSessionAction;
use App\Domain\AgentSession\Models\AgentSession;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;

#[IsDestructive]
#[AssistantTool('write')]
class AgentSessionForkTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_session_fork';

    protected string $description = 'Branch a session at a point in its event log. Creates a new Pending session under the SAME agent holding a copy of events 1..at_seq, plus the workspace_contract_snapshot. seq, kind and payload are preserved; the copies are stamped with the fork time so retention cleanup treats the child as new. The source keeps its status and continues running; it gains one fork event and a refreshed heartbeat. Use this to try an alternative path without losing the original run; use agent_session_handoff instead to move work to a different agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('AgentSession UUID to fork')->required(),
            'at_seq' => $schema->integer()->description('Source event seq to branch at (1-based). Defaults to the last event.'),
            'note' => $schema->string()->description('Optional note recorded on the fork event and the child metadata'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $validated = $request->validate([
            'session_id' => 'required|string',
            'at_seq' => 'nullable|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $source = AgentSession::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['session_id']);
        if (! $source) {
            return $this->notFoundError('agent_session');
        }

        try {
            $result = app(ForkAgentSessionAction::class)->execute(
                source: $source,
                atSeq: $validated['at_seq'] ?? null,
                note: $validated['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        } catch (RuntimeException $e) {
            // "no events yet" / "slice too large" are states of the session, not
            // bad input — the same call succeeds once the session changes.
            return $this->failedPreconditionError($e->getMessage());
        }

        return Response::json([
            'source' => [
                'id' => $result['source']->id,
                'status' => $result['source']->status->value,
            ],
            'child' => [
                'id' => $result['child']->id,
                'agent_id' => $result['child']->agent_id,
                'status' => $result['child']->status->value,
                'parent_session_id' => $result['child']->parent_session_id,
            ],
            'forked_at_seq' => $result['forked_at_seq'],
            'copied_events' => $result['copied_events'],
        ]);
    }
}
