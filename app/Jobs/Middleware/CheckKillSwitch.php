<?php

namespace App\Jobs\Middleware;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Closure;
use Illuminate\Support\Facades\Log;

class CheckKillSwitch
{
    public function handle(object $job, Closure $next): void
    {
        if (! property_exists($job, 'experimentId')) {
            if (property_exists($job, 'crewExecutionId') && ! $this->allowsCrewJob($job)) {
                return;
            }

            $next($job);

            return;
        }

        $experiment = Experiment::find($job->experimentId);

        if (! $experiment) {
            Log::warning('CheckKillSwitch: Experiment not found', [
                'experiment_id' => $job->experimentId,
            ]);

            return;
        }

        if ($experiment->status === ExperimentStatus::Killed) {
            Log::info('CheckKillSwitch: Experiment killed, skipping job', [
                'experiment_id' => $experiment->id,
                'job' => class_basename($job),
            ]);

            return;
        }

        if ($experiment->status === ExperimentStatus::Paused) {
            Log::info('CheckKillSwitch: Experiment paused, releasing job back to queue', [
                'experiment_id' => $experiment->id,
                'job' => class_basename($job),
            ]);
            $job->release(60);

            return;
        }

        $next($job);
    }

    /**
     * Crew jobs carry only crewExecutionId. Returns false when the job must be skipped.
     */
    private function allowsCrewJob(object $job): bool
    {
        // Queue workers have no auth/team context, so bypass the team scope.
        $execution = CrewExecution::withoutGlobalScopes()->find($job->crewExecutionId);

        if (! $execution) {
            // Crew jobs log and return for missing rows themselves.
            return true;
        }

        $context = [
            'crew_execution_id' => $execution->id,
            'experiment_id' => $execution->experiment_id,
            'team_id' => $execution->team_id,
            'job' => class_basename($job),
        ];

        // Read the raw value: PHPStan types the enum-cast column as string.
        $status = CrewExecutionStatus::tryFrom((string) $execution->getRawOriginal('status'));

        if ($status?->isTerminal()) {
            Log::info('CheckKillSwitch: Crew execution terminal, skipping job', $context + [
                'status' => $status->value,
            ]);

            return false;
        }

        // A paused crew execution is not stopped here: each crew job handles it
        // itself (ExecuteCrewTaskJob puts its task back to Pending so resume
        // dispatches it again; a queued validation still records its result).

        $experiment = $execution->experiment_id
            ? Experiment::withoutGlobalScopes()->find($execution->experiment_id)
            : null;

        if ($experiment?->status === ExperimentStatus::Killed) {
            Log::info('CheckKillSwitch: Experiment killed, skipping crew job', $context);

            return false;
        }

        if ($experiment?->status === ExperimentStatus::Paused) {
            // Releasing would use up the crew jobs' few attempts (1-2) and fail or
            // stall the crew. Instead pause the crew execution itself, as
            // crew_execution_pause does, and let the job run its own paused
            // handling; crew_execution_resume continues it.
            $paused = CrewExecution::withoutGlobalScopes()
                ->whereKey($execution->id)
                ->whereIn('status', [CrewExecutionStatus::Planning->value, CrewExecutionStatus::Executing->value])
                ->update(['status' => CrewExecutionStatus::Paused->value]);

            if ($paused > 0) {
                Log::info('CheckKillSwitch: Experiment paused, pausing crew execution', $context);
            }
        }

        return true;
    }
}
