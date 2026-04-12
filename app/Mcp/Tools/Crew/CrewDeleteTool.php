<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\Crew;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class CrewDeleteTool extends Tool
{
    protected string $name = 'crew_delete';

    protected string $description = 'Delete a crew. Only draft or archived crews can be deleted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()
                ->description('The crew UUID to delete.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $crew = Crew::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('crew_id'));

        if (! $crew) {
            return Response::error('Crew not found.');
        }

        try {
            $crew->delete();

            return Response::text(json_encode([
                'success' => true,
                'crew_id' => $crew->id,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
