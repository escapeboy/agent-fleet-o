<?php

namespace App\Mcp\Tools\AgentSession;

use App\Domain\AgentSession\Actions\HandoffAgentSessionAction;
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
class AgentSessionHandoffTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_session_handoff';

    protected string $description = 'Hand off an active or sleeping session to a different agent in the same team. Source moves to Sleeping; a new Pending target session inherits the workspace_contract_snapshot. HandoffOut + HandoffIn events are appended on both sides. Idempotent within 60 seconds of the same source→target_agent pair.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('Source AgentSession UUID')->required(),
            'target_agent_id' => $schema->string()->description('Agent UUID that should pick up the work; must belong to the same team and not be disabled')->required(),
            'note' => $schema->string()->description('Optional note recorded on both HandoffOut and HandoffIn events'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $validated = $request->validate([
            'session_id' => 'required|string',
            'target_agent_id' => "required|string|uuid|exists:agents,id,team_id,{$teamId}",
            'note' => 'nullable|string|max:255',
        ]);

        $source = AgentSession::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['session_id']);
        if (! $source) {
            return $this->notFoundError('agent_session');
        }

        $result = app(HandoffAgentSessionAction::class)
            ->execute($source, $validated['target_agent_id'], $validated['note'] ?? null);

        return Response::json([
            'source' => [
                'id' => $result['source']->id,
                'status' => $result['source']->status->value,
            ],
            'target' => [
                'id' => $result['target']->id,
                'agent_id' => $result['target']->agent_id,
                'status' => $result['target']->status->value,
            ],
            'reused' => $result['reused'],
        ]);
    }
}
