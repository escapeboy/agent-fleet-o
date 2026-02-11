<?php

namespace App\Console\Commands;

use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Notifications\ProjectBudgetWarningNotification;
use Illuminate\Console\Command;

class CheckProjectBudgets extends Command
{
    protected $signature = 'projects:check-budgets';

    protected $description = 'Check project budget caps, warn at 80%, pause at 100%, auto-resume on reset';

    public function handle(PauseProjectAction $pauseAction, ResumeProjectAction $resumeAction): int
    {
        $warned = 0;
        $paused = 0;
        $resumed = 0;

        // Check active continuous projects
        $activeProjects = Project::withoutGlobalScopes()
            ->where('status', ProjectStatus::Active)
            ->where('type', ProjectType::Continuous)
            ->get();

        foreach ($activeProjects as $project) {
            foreach (['daily', 'weekly', 'monthly'] as $period) {
                $cap = $project->budget_config["{$period}_cap"] ?? null;
                if (! $cap) {
                    continue;
                }

                $spend = $project->periodSpend($period);
                $percentage = ($spend / $cap) * 100;

                if ($percentage >= 100) {
                    $pauseAction->execute($project, "Budget cap exceeded ({$period}: {$spend}/{$cap})");
                    $paused++;
                    break; // No need to check other periods
                } elseif ($percentage >= 80) {
                    $notifyConfig = $project->notification_config;
                    if ($notifyConfig['on_budget_warning'] ?? true) {
                        $project->user->notify(new ProjectBudgetWarningNotification($project, $period, $spend, $cap));
                    }
                    $warned++;
                }
            }
        }

        // Auto-resume budget-paused projects
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
                $resumeAction->execute($project);
                $resumed++;
            }
        }

        $this->info("Budget check: {$warned} warned, {$paused} paused, {$resumed} resumed");

        return self::SUCCESS;
    }
}
