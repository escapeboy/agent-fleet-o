<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\Enums\MilestoneStatus;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMilestone;
use App\Domain\Project\Models\ProjectSchedule;
use Illuminate\Support\Facades\DB;

class CreateProjectAction
{
    public function __construct(
        private TriggerProjectRunAction $triggerRunAction,
    ) {}

    public function execute(
        string $userId,
        string $title,
        string $type,
        ?string $description = null,
        ?string $goal = null,
        ?string $crewId = null,
        ?string $workflowId = null,
        array $agentConfig = [],
        array $budgetConfig = [],
        array $notificationConfig = [],
        array $settings = [],
        ?array $schedule = null,
        array $milestones = [],
        ?string $teamId = null,
    ): Project {
        return DB::transaction(function () use (
            $userId, $title, $type, $description, $goal,
            $crewId, $workflowId, $agentConfig, $budgetConfig,
            $notificationConfig, $settings, $schedule, $milestones, $teamId,
        ) {
            $notificationDefaults = [
                'on_failure' => true,
                'on_milestone' => true,
                'on_budget_warning' => true,
                'digest' => 'daily',
                'channels' => ['database'],
            ];

            $project = Project::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'title' => $title,
                'type' => $type,
                'status' => ProjectStatus::Draft,
                'description' => $description,
                'goal' => $goal,
                'crew_id' => $crewId,
                'workflow_id' => $workflowId,
                'agent_config' => $agentConfig,
                'budget_config' => $budgetConfig,
                'notification_config' => array_merge($notificationDefaults, $notificationConfig),
                'settings' => $settings,
            ]);

            // Create schedule for continuous projects
            if ($schedule && $type === ProjectType::Continuous->value) {
                $projectSchedule = ProjectSchedule::create([
                    'project_id' => $project->id,
                    'frequency' => $schedule['frequency'] ?? 'daily',
                    'cron_expression' => $schedule['cron_expression'] ?? null,
                    'interval_minutes' => $schedule['interval_minutes'] ?? null,
                    'timezone' => $schedule['timezone'] ?? 'UTC',
                    'overlap_policy' => $schedule['overlap_policy'] ?? 'skip',
                    'max_consecutive_failures' => $schedule['max_consecutive_failures'] ?? 3,
                    'catchup_missed' => $schedule['catchup_missed'] ?? false,
                    'run_immediately' => $schedule['run_immediately'] ?? true,
                ]);

                // Calculate first next_run_at
                $nextRun = $projectSchedule->calculateNextRunAt();
                if ($nextRun) {
                    $projectSchedule->update(['next_run_at' => $nextRun]);
                    $project->update(['next_run_at' => $nextRun]);
                }
            }

            // Create milestones
            foreach ($milestones as $index => $milestone) {
                ProjectMilestone::create([
                    'project_id' => $project->id,
                    'title' => $milestone['title'],
                    'description' => $milestone['description'] ?? null,
                    'criteria' => $milestone['criteria'] ?? null,
                    'sort_order' => $index,
                    'status' => MilestoneStatus::Pending,
                ]);
            }

            // For one-shot projects, auto-start
            if ($type === ProjectType::OneShot->value) {
                $project->update([
                    'status' => ProjectStatus::Active,
                    'started_at' => now(),
                ]);
                $this->triggerRunAction->execute($project, 'initial');
            }

            return $project->fresh(['schedule', 'milestones', 'runs']);
        });
    }
}
