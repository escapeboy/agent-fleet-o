<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\Enums\MilestoneStatus;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use Illuminate\Support\Facades\DB;

class RestartProjectAction
{
    public function __construct(
        private readonly TriggerProjectRunAction $triggerRunAction,
    ) {}

    public function execute(Project $project): Project
    {
        $allowed = [
            ProjectStatus::Completed,
            ProjectStatus::Failed,
            ProjectStatus::Paused,
            ProjectStatus::Active,
        ];

        if (! in_array($project->status, $allowed)) {
            throw new \InvalidArgumentException(
                "Cannot restart a project in '{$project->status->value}' status.",
            );
        }

        return DB::transaction(function () use ($project) {
            // Reset project counters
            $project->update([
                'status' => ProjectStatus::Active,
                'total_runs' => 0,
                'successful_runs' => 0,
                'failed_runs' => 0,
                'total_spend_credits' => 0,
                'started_at' => now(),
                'paused_at' => null,
                'completed_at' => null,
                'last_run_at' => null,
            ]);

            // Reset milestones
            $project->milestones()->update([
                'status' => MilestoneStatus::Pending,
                'completed_at' => null,
            ]);

            // Reset schedule counters
            if ($schedule = $project->schedule) {
                $nextRun = $schedule->calculateNextRunAt();
                $schedule->update([
                    'last_run_at' => null,
                    'next_run_at' => $nextRun,
                    'enabled' => true,
                ]);
                $project->update(['next_run_at' => $nextRun]);
            }

            // Trigger the first run
            $this->triggerRunAction->execute($project->fresh(), 'restart');

            return $project->fresh();
        });
    }
}
