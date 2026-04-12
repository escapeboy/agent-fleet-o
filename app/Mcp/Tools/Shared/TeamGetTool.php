<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class TeamGetTool extends Tool
{
    protected string $name = 'team_get';

    protected string $description = 'Get the current team details including name, slug, owner, settings, and member count.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $team = Team::withCount('users')->find($teamId);

        if (! $team) {
            return Response::error('Team not found.');
        }

        return Response::text(json_encode([
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'owner_id' => $team->owner_id,
            'settings' => $team->settings,
            'member_count' => $team->users_count,
            'created_at' => $team->created_at->toIso8601String(),
            'updated_at' => $team->updated_at->toIso8601String(),
        ]));
    }
}
