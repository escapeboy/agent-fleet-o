<?php

namespace App\Domain\Project\Services;

use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Enums\OverlapPolicy;
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
            $dispatched += $this->processProject($project);
        }

        // Dispatch any queued runs from OverlapPolicy::Queue
        $this->dispatchQueuedRuns();

        // Check for budget-paused projects that can resume
        $this->checkBudgetResumptions();

        return $dispatched;
    }

    private function processProject(Project $project): int
    {
        $schedule = $project->schedule;

        // Handle catchup for missed runs
        if ($schedule->catchup_missed && $this->hasMissedRuns($project)) {
            return $this->catchupMissedRuns($project);
        }

        if ($this->canDispatch($project)) {
            ExecuteProjectRunJob::dispatch($project->id, 'schedule');
            // Schedule advancement moved to ExecuteProjectRunJob::handle()
            // to prevent phantom last_run_at when the job fails before creating a ProjectRun.

            return 1;
        }

        // If overlapping and policy is Queue, store the pending run
        if ($schedule->overlap_policy === OverlapPolicy::Queue && $project->activeRun() !== null) {
            if (! $schedule->queued_run_at) {
                $schedule->update(['queued_run_at' => now()]);
                Log::info("Project {$project->id}: overlapping run queued for later dispatch");
            }
            $this->advanceSchedule($project);
        }

        return 0;
    }

    private function canDispatch(Project $project): bool
    {
        $schedule = $project->schedule;

        // Check overlap policy
        if ($schedule->isOverlapping()) {
            if ($schedule->overlap_policy === OverlapPolicy::Queue) {
                // Queue policy — don't dispatch now, but don't reject either
                return false;
            }

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

    /**
     * Check if the project has missed runs (next_run_at is significantly in the past).
     */
    private function hasMissedRuns(Project $project): bool
    {
        $schedule = $project->schedule;
        if (! $schedule->last_run_at || ! $schedule->next_run_at) {
            return false;
        }

        // Check if we've missed more than one scheduled run
        $nextAfterLast = $schedule->calculateNextRunAt($schedule->last_run_at);
        if (! $nextAfterLast) {
            return false;
        }

        $secondNext = $schedule->calculateNextRunAt($nextAfterLast);

        return $secondNext && $secondNext->lte(now());
    }

    /**
     * Dispatch missed runs up to the configured maximum, with delay between each.
     */
    private function catchupMissedRuns(Project $project): int
    {
        $schedule = $project->schedule;
        $maxCatchup = config('projects.scheduling.max_catchup_count', 3);
        $delaySeconds = config('projects.scheduling.catchup_delay_seconds', 30);

        $missedCount = 0;
        $from = $schedule->last_run_at ?? $schedule->next_run_at;

        // Calculate missed run times
        $missedTimes = [];
        $current = $schedule->calculateNextRunAt($from);

        while ($current && $current->lte(now()) && count($missedTimes) < $maxCatchup) {
            $missedTimes[] = $current->copy();
            $current = $schedule->calculateNextRunAt($current);
        }

        if (empty($missedTimes)) {
            return 0;
        }

        // Check we can actually dispatch (budget, failures, etc.)
        if (! $this->canDispatch($project)) {
            return 0;
        }

        foreach ($missedTimes as $index => $missedAt) {
            $delay = $index * $delaySeconds;

            ExecuteProjectRunJob::dispatch($project->id, 'catchup')
                ->delay(now()->addSeconds($delay));

            Log::info("Project {$project->id}: dispatching catchup run #{$index} (missed at {$missedAt})", [
                'delay_seconds' => $delay,
            ]);

            $missedCount++;
        }

        // Advance schedule past all caught-up runs
        $schedule->update([
            'last_run_at' => now(),
            'next_run_at' => $schedule->calculateNextRunAt(now()),
        ]);
        $project->update(['next_run_at' => $schedule->fresh()->next_run_at]);

        Log::info("Project {$project->id}: caught up {$missedCount} missed runs (max: {$maxCatchup})");

        return $missedCount;
    }

    /**
     * Dispatch queued runs for projects that were waiting due to OverlapPolicy::Queue.
     */
    private function dispatchQueuedRuns(): void
    {
        $projectsWithQueuedRuns = Project::withoutGlobalScopes()
            ->where('status', ProjectStatus::Active)
            ->where('type', ProjectType::Continuous)
            ->whereHas('schedule', function ($q) {
                $q->whereNotNull('queued_run_at');
            })
            ->with('schedule')
            ->get();

        foreach ($projectsWithQueuedRuns as $project) {
            // Only dispatch if no active run (the overlap is resolved)
            if ($project->activeRun() === null) {
                $project->schedule->update(['queued_run_at' => null]);

                ExecuteProjectRunJob::dispatch($project->id, 'queued');

                Log::info("Project {$project->id}: dispatching queued run (previous run completed)");
            }
        }
    }

    public function advanceSchedule(Project $project): void
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
