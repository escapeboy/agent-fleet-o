<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class CrewMemberRemoveTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_member_remove';

    protected string $description = 'Remove an agent from a crew.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()->description('The crew ID.')->required(),
            'agent_id' => $schema->string()->description('The agent ID to remove from the crew.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $crew = Crew::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('crew_id'));
        if (! $crew) {
            return $this->notFoundError('crew');
        }

        $member = CrewMember::where('crew_id', $crew->id)
            ->where('agent_id', $request->get('agent_id'))
            ->first();

        if (! $member) {
            return $this->failedPreconditionError('Agent is not a member of this crew.');
        }

        $member->delete();

        return Response::text(json_encode([
            'success' => true,
            'crew_id' => $crew->id,
            'agent_id' => $request->get('agent_id'),
        ]));
    }
}
