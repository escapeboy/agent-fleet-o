<?php

namespace App\Mcp\Tools\AgentSession;

use App\Domain\AgentSession\Actions\ReplayAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Models\AgentSession;
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
class AgentSessionReplayTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_session_replay';

    protected string $description = 'Reconstruct an AgentSession by streaming its append-only event log plus session-wide summary stats (counts by kind, llm tokens/cost, tool failures, handoff count). Supports paginated streaming via since_seq. Replay = event reconstruction; deterministic re-execution against a fresh agent is a separate concern.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('AgentSession UUID')->required(),
            'since_seq' => $schema->number()->description('Return events with seq strictly greater than this. Use 0 (default) for first page; pass the response\'s next_since_seq for subsequent pages.'),
            'limit' => $schema->number()->description('Max events to return in this slice. Default 1000, hard max 5000.'),
            'kinds' => $schema->string()->description('Comma-separated event kinds to filter the slice (e.g. "tool_call,llm_call"). Stats still cover all events. Allowed values: '.implode(',', array_map(fn ($k) => $k->value, AgentSessionEventKind::cases()))),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'since_seq' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1',
            'kinds' => 'nullable|string',
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

        $kinds = null;
        if (! empty($validated['kinds'])) {
            $kinds = [];
            foreach (explode(',', $validated['kinds']) as $raw) {
                $value = trim($raw);
                if ($value === '') {
                    continue;
                }
                $kind = AgentSessionEventKind::tryFrom($value);
                if ($kind === null) {
                    return $this->invalidArgumentError("Unknown event kind '{$value}'.");
                }
                $kinds[] = $kind;
            }
            if ($kinds === []) {
                $kinds = null;
            }
        }

        $summary = app(ReplayAgentSessionAction::class)->execute(
            session: $session,
            sinceSeq: (int) ($validated['since_seq'] ?? 0),
            limit: isset($validated['limit']) ? (int) $validated['limit'] : null,
            kinds: $kinds,
        );

        return Response::json($summary->toArray());
    }
}
