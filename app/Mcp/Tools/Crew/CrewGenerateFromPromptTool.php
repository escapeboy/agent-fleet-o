<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Actions\GenerateCrewFromPromptAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CrewGenerateFromPromptTool extends Tool
{
    protected string $name = 'crew_generate_from_prompt';

    protected string $description = 'Generate a crew design from a natural language goal description. '
        .'Returns a structured proposal with coordinator, QA agent, worker roles, skills, and process type. '
        .'Does not create the crew — use crew_create after reviewing the design.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()
                ->description('Natural language description of what the crew should achieve')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'goal' => 'required|string|max:2000',
        ]);

        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : auth()->user()?->current_team_id;

        try {
            $design = app(GenerateCrewFromPromptAction::class)->execute(
                goal: $validated['goal'],
                teamId: $teamId,
            );

            return Response::text(json_encode([
                'success' => true,
                'design' => $design,
                'next_step' => 'Review the design above. To create this crew, use crew_create and agent_create with the suggested configuration.',
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
