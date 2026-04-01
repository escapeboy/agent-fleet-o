<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Crew\Actions\GenerateCrewFromPromptAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DesignCrewTool implements Tool
{
    public function name(): string
    {
        return 'design_crew';
    }

    public function description(): string
    {
        return 'Design a crew of AI agents for a given goal. Returns a structured crew definition including coordinator, QA agent, worker roles, skills, and process type. Use create_crew to actually create it after reviewing the design.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()->required()->description('What should this crew achieve? Describe the goal in plain language.'),
            'team_id' => $schema->string()->description('Team ID (optional, uses current team if omitted)'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $teamId = $request->get('team_id') ?? auth()->user()?->current_team_id;
            $design = app(GenerateCrewFromPromptAction::class)->execute($request->get('goal'), $teamId);

            return json_encode([
                'success' => true,
                'design' => $design,
                'next_step' => 'Review the design above. To create this crew, use the create_crew and create_agent tools with the suggested configuration.',
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
