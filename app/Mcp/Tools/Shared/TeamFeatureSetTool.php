<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\TeamFeatures;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TeamFeatureSetTool extends Tool
{
    protected string $name = 'team_feature_set';

    protected string $description = 'Turn a platform feature on or off for the current team (see team_feature_list for keys). Requires the owner or admin role. A feature the platform has switched off cannot be turned on.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Feature key from team_feature_list, e.g. semantic_cache')->required(),
            'enabled' => $schema->boolean()->description('true to turn the feature on for the team, false to turn it off')->required(),
        ];
    }

    public function handle(Request $request, TeamFeatures $features): Response
    {
        $user = auth()->user();
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : $user?->current_team_id;
        $team = $teamId ? Team::find($teamId) : null;

        if (! $user || ! $team) {
            return Response::error('Authentication or team context missing.');
        }

        if (! $user->can('manage-team', $team)) {
            return Response::error('You do not have permission to manage team settings (requires owner or admin).');
        }

        $key = (string) $request->get('key');
        if (! $features->exists($key)) {
            return Response::error("Unknown team feature: {$key}. Use team_feature_list for valid keys.");
        }

        $enabled = (bool) $request->get('enabled');
        if ($enabled && ! $features->platformEnabled($key)) {
            return Response::error("{$key} is turned off for the whole platform and cannot be enabled per team.");
        }

        $features->set($team, $key, $enabled);

        return Response::text(json_encode([
            'key' => $key,
            'team_choice' => $enabled,
            'enabled' => $features->enabled($key, $team),
        ]));
    }
}
