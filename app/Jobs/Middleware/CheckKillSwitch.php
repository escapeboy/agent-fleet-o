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
        if (property_exists($job, 'experimentId')) {
            $experiment = Experiment::find($job->experimentId);

            if (! $experiment) {
                Log::warning('CheckKillSwitch: Experiment not found', [
                    'experiment_id' => $job->experimentId,
                ]);

                return;
            }

            if ($this->blockedByExperiment($job, $experiment)) {
                return;
            }

            $next($job);

            return;
        }

        if (property_exists($job, 'crewExecutionId')) {
            // Queue workers have no auth/team context, so bypass the team scope.
            $execution = CrewExecution::withoutGlobalScopes()->find($job->crewExecutionId);

            if (! $execution) {
                // Crew jobs log and return for missing rows themselves.
                $next($job);

                return;
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

                return;
            }

            // A paused crew execution is not stopped here: each crew job handles it
            // itself (ExecuteCrewTaskJob puts its task back to Pending so resume
            // dispatches it again; a queued validation must still record its result).

            if ($execution->experiment_id) {
                $experiment = Experiment::withoutGlobalScopes()->find($execution->experiment_id);

                if ($experiment && $this->blockedByExperiment($job, $experiment, $context)) {
                    return;
                }
            }
        }

        $next($job);
    }

    /**
     * Returns true when the experiment's status stops the job (killed: skip,
     * paused: release back to the queue).
     *
     * @param  array<string, mixed>  $context
     */
    private function blockedByExperiment(object $job, Experiment $experiment, array $context = []): bool
    {
        if ($experiment->status === ExperimentStatus::Killed) {
            Log::info('CheckKillSwitch: Experiment killed, skipping job', $context + [
                'experiment_id' => $experiment->id,
                'job' => class_basename($job),
            ]);

            return true;
        }

        if ($experiment->status === ExperimentStatus::Paused) {
            Log::info('CheckKillSwitch: Experiment paused, releasing job back to queue', $context + [
                'experiment_id' => $experiment->id,
                'job' => class_basename($job),
            ]);
            $job->release(60);

            return true;
        }

        return false;
    }
}
