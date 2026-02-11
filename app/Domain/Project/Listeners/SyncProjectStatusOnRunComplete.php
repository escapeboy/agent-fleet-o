<?php

namespace App\Domain\Project\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Project\Notifications\ProjectRunFailedNotification;
use Illuminate\Support\Facades\Log;

class SyncProjectStatusOnRunComplete
{
    public function __construct(
        private PauseProjectAction $pauseAction,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        if (! $event->toState->isTerminal() && ! $event->toState->isFailed()) {
            return;
        }

        // Find the ProjectRun that wraps this experiment
        $run = ProjectRun::where('experiment_id', $event->experiment->id)->first();

        if (! $run) {
            return;
        }

        $project = $run->project;

        if ($event->toState === ExperimentStatus::Completed) {
            $this->handleRunCompleted($run, $project);
        } elseif ($event->toState->isFailed() || $event->toState === ExperimentStatus::Killed) {
            $this->handleRunFailed($run, $project, $event);
        }
    }

    private function handleRunCompleted(ProjectRun $run, $project): void
    {
        $run->update([
            'status' => ProjectRunStatus::Completed,
            'completed_at' => now(),
            'spend_credits' => $run->experiment?->budget_spent_credits ?? 0,
        ]);

        $project->update([
            'successful_runs' => $project->successful_runs + 1,
            'total_spend_credits' => $project->total_spend_credits + ($run->experiment?->budget_spent_credits ?? 0),
            'last_run_at' => now(),
        ]);

        // One-shot projects complete when their run completes
        if ($project->type === ProjectType::OneShot) {
            $project->update([
                'status' => ProjectStatus::Completed,
                'completed_at' => now(),
            ]);
        }

        // Check milestones
        $this->evaluateMilestones($project, $run);

        Log::info("Project {$project->id} run #{$run->run_number} completed");
    }

    private function handleRunFailed(ProjectRun $run, $project, ExperimentTransitioned $event): void
    {
        $run->update([
            'status' => ProjectRunStatus::Failed,
            'completed_at' => now(),
            'spend_credits' => $run->experiment?->budget_spent_credits ?? 0,
            'error_message' => "Experiment reached state: {$event->toState->value}",
        ]);

        $project->update([
            'failed_runs' => $project->failed_runs + 1,
            'total_spend_credits' => $project->total_spend_credits + ($run->experiment?->budget_spent_credits ?? 0),
            'last_run_at' => now(),
        ]);

        // One-shot projects fail when their run fails
        if ($project->type === ProjectType::OneShot) {
            $project->update(['status' => ProjectStatus::Failed]);
        }

        // Notify on failure
        $notifyConfig = $project->notification_config;
        if ($notifyConfig['on_failure'] ?? true) {
            $project->user->notify(new ProjectRunFailedNotification($project, $run));
        }

        // Check consecutive failures for continuous projects
        if ($project->isContinuous()) {
            $maxFailures = $project->schedule?->max_consecutive_failures ?? 3;
            if ($project->consecutiveFailures() >= $maxFailures) {
                $project->update(['status' => ProjectStatus::Failed]);
                Log::warning("Project {$project->id} failed after {$maxFailures} consecutive failures");
            }
        }

        Log::info("Project {$project->id} run #{$run->run_number} failed");
    }

    private function evaluateMilestones($project, ProjectRun $run): void
    {
        $pendingMilestones = $project->milestones()
            ->where('status', 'pending')
            ->orWhere('status', 'in_progress')
            ->get();

        foreach ($pendingMilestones as $milestone) {
            $criteria = $milestone->criteria;
            if (! $criteria) {
                continue;
            }

            $completed = match ($criteria['type'] ?? null) {
                'run_count' => $project->successful_runs >= ($criteria['target'] ?? PHP_INT_MAX),
                'metric' => false, // Future: check metric aggregations
                default => false,
            };

            if ($completed) {
                $milestone->markComplete($run);
            }
        }
    }
}
