<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Actions\ExecuteCrewAction;
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
class CrewExecuteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_execute';

    protected string $description = 'Execute a crew with a given goal. The crew must be active. Returns immediately (async execution).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()
                ->description('The crew UUID')
                ->required(),
            'goal' => $schema->string()
                ->description('The goal/task for the crew to accomplish')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'crew_id' => 'required|string',
            'goal' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $crew = Crew::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['crew_id']);

        if (! $crew) {
            return $this->notFoundError('crew');
        }

        try {
            $execution = app(ExecuteCrewAction::class)->execute(
                crew: $crew,
                goal: $validated['goal'],
                teamId: auth()->user()->current_team_id,
            );

            return Response::text(json_encode([
                'success' => true,
                'execution_id' => $execution->id,
                'status' => $execution->status->value,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
