<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\DTOs\ScheduleParseResultDTO;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectSchedule;
use App\Domain\Project\Services\NaturalLanguageScheduleParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduleProjectFromNaturalLanguageAction
{
    public function __construct(
        private readonly NaturalLanguageScheduleParser $parser,
        private readonly CreateProjectAction $createProject,
    ) {}

    /**
     * Parse a natural language frequency and either create a new scheduled project
     * or add a schedule to an existing one.
     *
     * Returns a JSON string describing the result, for use as a PrismPHP tool response.
     */
    public function execute(
        string $title,
        string $frequencyDescription,
        string $goal,
        string $teamId,
        string $userId,
        ?string $existingProjectId = null,
    ): string {
        try {
            $scheduleDto = $this->parser->parse($frequencyDescription);

            $scheduleArray = [
                'frequency' => $scheduleDto->frequency->value,
                'cron_expression' => $scheduleDto->cronExpression,
                'timezone' => $scheduleDto->timezone,
                'overlap_policy' => $scheduleDto->overlapPolicy->value,
                'run_immediately' => false,
                'catchup_missed' => false,
                'max_consecutive_failures' => 3,
            ];

            if ($existingProjectId) {
                return $this->addScheduleToExistingProject(
                    $existingProjectId, $scheduleDto, $scheduleArray, $teamId,
                );
            }

            $project = $this->createProject->execute(
                userId: $userId,
                title: $title,
                type: ProjectType::Continuous->value,
                goal: $goal,
                schedule: $scheduleArray,
                teamId: $teamId,
            );

            return json_encode([
                'status' => 'created',
                'project_id' => $project->id,
                'project_title' => $project->title,
                'schedule_summary' => $scheduleDto->humanReadable,
                'next_run_at' => $project->next_run_at?->toIso8601String(),
                'project_url' => route('projects.show', $project),
            ]);
        } catch (\Throwable $e) {
            Log::error('ScheduleProjectFromNaturalLanguageAction: failed', [
                'error' => $e->getMessage(),
                'title' => $title,
            ]);

            return json_encode(['error' => $e->getMessage()]);
        }
    }

    private function addScheduleToExistingProject(
        string $projectId,
        ScheduleParseResultDTO $scheduleDto,
        array $scheduleArray,
        string $teamId,
    ): string {
        $project = Project::where('id', $projectId)->where('team_id', $teamId)->first();

        if (! $project) {
            return json_encode(['error' => "Project {$projectId} not found."]);
        }

        return DB::transaction(function () use ($project, $scheduleDto, $scheduleArray) {
            // Ensure project type is Continuous
            if ($project->type !== ProjectType::Continuous) {
                $project->update(['type' => ProjectType::Continuous]);
            }

            $projectSchedule = ProjectSchedule::updateOrCreate(
                ['project_id' => $project->id],
                [
                    'frequency' => $scheduleArray['frequency'],
                    'cron_expression' => $scheduleArray['cron_expression'],
                    'timezone' => $scheduleArray['timezone'],
                    'overlap_policy' => $scheduleArray['overlap_policy'],
                    'run_immediately' => false,
                    'catchup_missed' => false,
                    'max_consecutive_failures' => 3,
                    'enabled' => true,
                ],
            );

            $nextRun = $projectSchedule->calculateNextRunAt();
            if ($nextRun) {
                $projectSchedule->update(['next_run_at' => $nextRun]);
                $project->update(['next_run_at' => $nextRun]);
            }

            return json_encode([
                'status' => 'schedule_added',
                'project_id' => $project->id,
                'project_title' => $project->title,
                'schedule_summary' => $scheduleDto->humanReadable,
                'next_run_at' => $nextRun?->toIso8601String(),
                'project_url' => route('projects.show', $project),
            ]);
        });
    }
}
