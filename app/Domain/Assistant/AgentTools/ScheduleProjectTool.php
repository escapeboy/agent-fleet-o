<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Actions\ScheduleProjectFromNaturalLanguageAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ScheduleProjectTool implements Tool
{
    public function name(): string
    {
        return 'schedule_project';
    }

    public function description(): string
    {
        return 'Create a scheduled project that runs automatically on a recurring basis. Use when the user says they want to run something "every day", "every Monday", "weekly", "hourly", etc. Always confirm the schedule with the user after creating it.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_title' => $schema->string()->required()->description('A short title for the project'),
            'frequency_description' => $schema->string()->required()->description('Natural language description of how often to run. Examples: "every Monday at 9am", "daily at 6pm", "every 15 minutes", "weekly on Fridays", "every hour".'),
            'project_goal' => $schema->string()->required()->description('What the project should accomplish each time it runs'),
            'project_id' => $schema->string()->description('Optional: UUID of an existing project to add a schedule to instead of creating a new one.'),
        ];
    }

    public function handle(Request $request): string
    {
        return app(ScheduleProjectFromNaturalLanguageAction::class)->execute(
            title: $request->get('project_title'),
            frequencyDescription: $request->get('frequency_description'),
            goal: $request->get('project_goal'),
            teamId: auth()->user()->current_team_id,
            userId: auth()->id(),
            existingProjectId: $request->get('project_id'),
        );
    }
}
