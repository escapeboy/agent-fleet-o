<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Models\Crew;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ExecuteCrewTool implements Tool
{
    public function name(): string
    {
        return 'execute_crew';
    }

    public function description(): string
    {
        return 'Start a crew execution with a goal. The crew must be active.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()->required()->description('The crew UUID'),
            'goal' => $schema->string()->required()->description('The goal or task for the crew to accomplish'),
        ];
    }

    public function handle(Request $request): string
    {
        $crew = Crew::find($request->get('crew_id'));
        if (! $crew) {
            return json_encode(['error' => 'Crew not found']);
        }

        try {
            $execution = app(ExecuteCrewAction::class)->execute(
                crew: $crew,
                goal: $request->get('goal'),
                teamId: auth()->user()->current_team_id,
            );

            return json_encode([
                'success' => true,
                'execution_id' => $execution->id,
                'crew_name' => $crew->name,
                'status' => $execution->status->value,
                'url' => route('crews.show', $crew),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
