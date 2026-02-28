<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Project\Actions\ScheduleProjectFromNaturalLanguageAction;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class SchedulingTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function tools(string $teamId, string $userId): array
    {
        return [
            self::scheduleProject($teamId, $userId),
        ];
    }

    private static function scheduleProject(string $teamId, string $userId): PrismToolObject
    {
        return PrismTool::as('schedule_project')
            ->for(
                'Create a scheduled project that runs automatically on a recurring basis. '.
                'Use when the user says they want to run something "every day", "every Monday", "weekly", "hourly", etc. '.
                'Always confirm the schedule with the user after creating it.'
            )
            ->withStringParameter('project_title', 'A short title for the project', required: true)
            ->withStringParameter(
                'frequency_description',
                'Natural language description of how often to run. Examples: "every Monday at 9am", "daily at 6pm", "every 15 minutes", "weekly on Fridays", "every hour".',
                required: true
            )
            ->withStringParameter('project_goal', 'What the project should accomplish each time it runs', required: true)
            ->withStringParameter(
                'project_id',
                'Optional: UUID of an existing project to add a schedule to instead of creating a new one.',
                required: false
            )
            ->using(function (
                string $project_title,
                string $frequency_description,
                string $project_goal,
                ?string $project_id = null,
            ) use ($teamId, $userId): string {
                return app(ScheduleProjectFromNaturalLanguageAction::class)->execute(
                    title: $project_title,
                    frequencyDescription: $frequency_description,
                    goal: $project_goal,
                    teamId: $teamId,
                    userId: $userId,
                    existingProjectId: $project_id,
                );
            });
    }
}
