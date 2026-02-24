<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class TeamMembersTool extends Tool
{
    protected string $name = 'team_members';

    protected string $description = 'List all members of the current team with their roles and join dates.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $team = Team::with('users')->find($teamId);

        if (! $team) {
            return Response::error('Team not found.');
        }

        $members = $team->users->map(function ($user) {
            /** @var User $user */
            $pivot = $user->pivot;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $pivot?->role,
                'joined_at' => $pivot?->created_at?->toIso8601String(),
            ];
        });

        return Response::text(json_encode([
            'team_id' => $team->id,
            'count' => $members->count(),
            'members' => $members->toArray(),
        ]));
    }
}
