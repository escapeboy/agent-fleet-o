<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TeamInviteMemberTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'team_invite_member';

    protected string $description = 'Invite a new team member by email address.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->description('Email address of the person to invite.')->required(),
            'role' => $schema->string()->description('Role to assign: admin, member, or viewer (default: member).')->enum(['admin', 'member', 'viewer']),
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

        $email = $request->get('email');
        $role = $request->get('role', 'member');

        // Validate the role
        $teamRole = TeamRole::tryFrom($role);
        if (! $teamRole) {
            return $this->invalidArgumentError('Invalid role. Must be one of: admin, member, viewer.');
        }

        // Use the InviteTeamMemberAction if available (cloud edition), otherwise create invitation directly
        $invitationClass = 'Cloud\\Domain\\Shared\\Models\\TeamInvitation';
        $actionClass = 'Cloud\\Domain\\Shared\\Actions\\InviteTeamMemberAction';

        if (class_exists($actionClass)) {
            try {
                $user = auth()->user();
                $invitation = app($actionClass)->execute($team, $email, $role, $user);

                return Response::text(json_encode([
                    'success' => true,
                    'invitation_id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                ]));
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        if (class_exists($invitationClass)) {
            if ($invitationClass::where('team_id', $teamId)->where('email', $email)->whereNull('accepted_at')->exists()) {
                return $this->failedPreconditionError('An invitation has already been sent to this email.');
            }

            $invitation = $invitationClass::create([
                'team_id' => $teamId,
                'email' => $email,
                'role' => $role,
                'token' => Str::random(64),
                'invited_by' => auth()->id(),
                'expires_at' => now()->addDays(7),
            ]);

            return Response::text(json_encode([
                'success' => true,
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
            ]));
        }

        return $this->failedPreconditionError('Team invitations are not supported in this edition.');
    }
}
