<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CrewActivateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_activate';

    protected string $description = 'Activate a draft crew to make it ready for execution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()->description('The crew ID to activate.')->required(),
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

        $crew->status = CrewStatus::Active;
        $crew->save();

        return Response::text(json_encode([
            'success' => true,
            'id' => $crew->id,
            'name' => $crew->name,
            'status' => $crew->status->value,
        ]));
    }
}
