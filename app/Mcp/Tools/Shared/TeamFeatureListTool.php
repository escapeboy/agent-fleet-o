<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\TeamFeatures;
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
class TeamFeatureListTool extends Tool
{
    protected string $name = 'team_feature_list';

    protected string $description = 'List the platform features the current team can turn on or off for itself, with the platform state, the team\'s own choice (null = never chose, default applies) and whether the feature runs for the team.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, TeamFeatures $features): Response
    {
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : auth()->user()?->current_team_id;
        $team = $teamId ? Team::find($teamId) : null;

        if (! $team) {
            return Response::error('No team context.');
        }

        return Response::text(json_encode(['features' => $features->overview($team)]));
    }
}
