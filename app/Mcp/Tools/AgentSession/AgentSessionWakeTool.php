<?php

namespace App\Mcp\Tools\AgentSession;

use App\Domain\AgentSession\Actions\WakeAgentSessionAction;
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
class AgentSessionWakeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_session_wake';

    protected string $description = 'Wake an AgentSession from any prior state (Pending|Sleeping). Returns SessionContext (recent events + workspace_contract_snapshot) so a fresh sandbox can rehydrate without losing prior state. Records a Wake event in the log.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('AgentSession UUID')->required(),
            'sandbox_id' => $schema->string()->description('Optional sandbox identifier waking the session'),
            'recent_event_limit' => $schema->integer()->description('Recent events to return (default 50, max 500)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'sandbox_id' => 'nullable|string',
            'recent_event_limit' => 'nullable|integer|min:1|max:500',
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

        $context = app(WakeAgentSessionAction::class)->execute(
            session: $session,
            sandboxId: $validated['sandbox_id'] ?? null,
            recentEventLimit: (int) ($validated['recent_event_limit'] ?? 50),
        );

        return Response::json($context->toArray());
    }
}
