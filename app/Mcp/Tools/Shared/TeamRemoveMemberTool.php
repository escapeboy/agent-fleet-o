<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Events\TeamMemberRemoved;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class TeamRemoveMemberTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'team_remove_member';

    protected string $description = 'Remove a member from the team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->string()->description('The user ID of the team member to remove.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! $team) {
            return $this->notFoundError('team');
        }

        $userId = $request->get('user_id');

        $member = $team->users()->where('users.id', $userId)->first();
        if (! $member) {
            return $this->failedPreconditionError('User is not a member of this team.');
        }

        if (TeamRole::from($member->pivot->role) === TeamRole::Owner) {
            return $this->failedPreconditionError('Cannot remove the team owner.');
        }

        $team->users()->detach($userId);

        event(new TeamMemberRemoved($team, $userId));

        return Response::text(json_encode([
            'success' => true,
            'user_id' => $userId,
        ]));
    }
}
