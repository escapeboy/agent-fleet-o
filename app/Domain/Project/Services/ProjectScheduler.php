<?php

namespace App\Domain\Project\Services;

use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Jobs\ExecuteProjectRunJob;
use App\Domain\Project\Models\Project;
use Illuminate\Support\Facades\Log;

class ProjectScheduler
{
    public function __construct(
        private PauseProjectAction $pauseAction,
        private ResumeProjectAction $resumeAction,
    ) {}

    public function evaluateDueProjects(): int
    {
        $dispatched = 0;

        // Find active continuous projects with due schedules
        $dueProjects = Project::withoutGlobalScopes()
            ->where('status', ProjectStatus::Active)
            ->where('type', ProjectType::Continuous)
            ->whereHas('schedule', function ($q) {
                $q->where('enabled', true)
                    ->where('next_run_at', '<=', now());
            })
            ->with('schedule')
            ->get();

        foreach ($dueProjects as $project) {
            if ($this->canDispatch($project)) {
                ExecuteProjectRunJob::dispatch($project->id, 'schedule');
                $this->advanceSchedule($project);
                $dispatched++;
            }
        }

        // Check for budget-paused projects that can resume
        $this->checkBudgetResumptions();

        return $dispatched;
    }

    private function canDispatch(Project $project): bool
    {
        $schedule = $project->schedule;

        // Check overlap policy
        if ($schedule->isOverlapping()) {
            Log::debug("Project {$project->id} skipped — previous run still active (overlap_policy: {$schedule->overlap_policy->value})");

            return false;
        }

        // Check consecutive failure threshold
        $failures = $project->consecutiveFailures();
        if ($failures >= $schedule->max_consecutive_failures) {
            Log::warning("Project {$project->id} exceeded max consecutive failures ({$failures}/{$schedule->max_consecutive_failures})");
            $project->update(['status' => ProjectStatus::Failed]);

            return false;
        }

        // Check budget caps
        foreach (['daily', 'weekly', 'monthly'] as $period) {
            if ($project->isOverBudget($period)) {
                Log::info("Project {$project->id} over {$period} budget — pausing");
                $this->pauseAction->execute($project, "Budget cap exceeded ({$period})");

                return false;
            }
        }

        return true;
    }

    private function advanceSchedule(Project $project): void
    {
        $schedule = $project->schedule;
        $nextRun = $schedule->calculateNextRunAt();

        $schedule->update([
            'last_run_at' => now(),
            'next_run_at' => $nextRun,
        ]);

        $project->update(['next_run_at' => $nextRun]);
    }

    private function checkBudgetResumptions(): void
    {
        // Find projects paused due to budget that can now resume
        $pausedProjects = Project::withoutGlobalScopes()
            ->where('status', ProjectStatus::Paused)
            ->where('paused_from_status', ProjectStatus::Active->value)
            ->where('type', ProjectType::Continuous)
            ->get();

        foreach ($pausedProjects as $project) {
            $canResume = true;
            foreach (['daily', 'weekly', 'monthly'] as $period) {
                if ($project->isOverBudget($period)) {
                    $canResume = false;
                    break;
                }
            }

            if ($canResume) {
                Log::info("Project {$project->id} budget reset — auto-resuming");
                $this->resumeAction->execute($project);
            }
        }
    }
}
