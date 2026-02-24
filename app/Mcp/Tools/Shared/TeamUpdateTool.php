<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TeamUpdateTool extends Tool
{
    protected string $name = 'team_update';

    protected string $description = 'Update the current team name or settings.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('New team name'),
            'settings' => $schema->object()
                ->description('Team settings object (merged with existing settings)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $team = Team::find($teamId);

        if (! $team) {
            return Response::error('Team not found.');
        }

        $updates = array_filter([
            'name' => $request->get('name'),
            'settings' => $request->get('settings'),
        ], fn ($v) => $v !== null);

        if (empty($updates)) {
            return Response::error('No fields to update. Provide name or settings.');
        }

        $team->update($updates);
        $team->refresh();

        return Response::text(json_encode([
            'success' => true,
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'settings' => $team->settings,
            'updated_at' => $team->updated_at->toIso8601String(),
        ]));
    }
}
