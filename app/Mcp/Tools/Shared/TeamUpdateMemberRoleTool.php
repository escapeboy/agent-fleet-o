<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TeamUpdateMemberRoleTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'team_update_member_role';

    protected string $description = 'Update the role of an existing team member.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->string()->description('The user ID of the team member.')->required(),
            'role' => $schema->string()->description('New role: admin, member, or viewer.')->required()->enum(['admin', 'member', 'viewer']),
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
        $role = $request->get('role');

        $teamRole = TeamRole::tryFrom($role);
        if (! $teamRole) {
            return $this->invalidArgumentError('Invalid role. Must be one of: admin, member, viewer.');
        }

        if (! $team->users()->where('users.id', $userId)->exists()) {
            return $this->failedPreconditionError('User is not a member of this team.');
        }

        $team->users()->updateExistingPivot($userId, ['role' => $role]);

        return Response::text(json_encode([
            'success' => true,
            'user_id' => $userId,
            'role' => $role,
        ]));
    }
}
