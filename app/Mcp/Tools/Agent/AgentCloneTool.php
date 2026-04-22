<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentCloneTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_clone';

    protected string $description = 'Clone an existing agent with a new name.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('The agent ID to clone.')->required(),
            'name' => $schema->string()->description('New name for the cloned agent.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('agent_id'));
        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $clone = $agent->replicate();
        $clone->name = $request->get('name');
        $clone->status = AgentStatus::Disabled;
        $clone->save();

        return Response::text(json_encode([
            'success' => true,
            'id' => $clone->id,
            'name' => $clone->name,
            'status' => $clone->status->value,
            'cloned_from' => $agent->id,
        ]));
    }
}
