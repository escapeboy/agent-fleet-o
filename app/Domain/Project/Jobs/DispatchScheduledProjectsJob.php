<?php

namespace App\Domain\Project\Jobs;

use App\Domain\Project\Actions\ExecuteHeartbeatTurnAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Services\ProjectScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DispatchScheduledProjectsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct()
    {
        $this->onQueue('critical');
    }

    public function handle(ProjectScheduler $scheduler, ExecuteHeartbeatTurnAction $heartbeatAction): void
    {
        $dispatched = $scheduler->evaluateDueProjects();

        if ($dispatched > 0) {
            Log::info("ProjectScheduler dispatched {$dispatched} project run(s)");
        }

        $this->processHeartbeats($heartbeatAction);
    }

    private function processHeartbeats(ExecuteHeartbeatTurnAction $heartbeatAction): void
    {
        $projects = Project::withoutGlobalScopes()
            ->where('status', ProjectStatus::Active)
            ->where('type', ProjectType::Continuous)
            ->whereHas('schedule', fn ($q) => $q->where('heartbeat_enabled', true))
            ->with('schedule')
            ->get();

        foreach ($projects as $project) {
            $schedule = $project->schedule;
            $intervalMinutes = $schedule->heartbeat_interval_minutes;

            if (! $intervalMinutes) {
                continue;
            }

            $lastRun = $schedule->last_run_at;
            if ($lastRun && $lastRun->diffInMinutes(now()) < $intervalMinutes) {
                continue;
            }

            try {
                $run = $heartbeatAction->execute($project);
                if ($run) {
                    Log::info("Heartbeat triggered run for project {$project->id}");
                }
            } catch (\Throwable $e) {
                Log::error("Heartbeat failed for project {$project->id}: {$e->getMessage()}");
            }
        }
    }
}
