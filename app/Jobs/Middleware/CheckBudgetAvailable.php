<?php

namespace App\Jobs\Middleware;

use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Models\Experiment;
use Closure;
use Illuminate\Support\Facades\Log;

class CheckBudgetAvailable
{
    public function handle(object $job, Closure $next): void
    {
        $execution = null;

        if (property_exists($job, 'experimentId')) {
            $experiment = Experiment::find($job->experimentId);

            if (! $experiment) {
                $next($job);

                return;
            }

            $teamId = $experiment->team_id;
        } elseif (property_exists($job, 'crewExecutionId')) {
            // Queue workers have no auth/team context, so bypass the team scope.
            $execution = CrewExecution::withoutGlobalScopes()->find($job->crewExecutionId);

            if (! $execution) {
                $next($job);

                return;
            }

            $experiment = $execution->experiment_id
                ? Experiment::withoutGlobalScopes()->find($execution->experiment_id)
                : null;
            $teamId = $execution->team_id;
        } else {
            $next($job);

            return;
        }

        $crewContext = $execution ? ['crew_execution_id' => $execution->id] : [];

        // Check experiment budget cap
        if ($experiment
            && $experiment->budget_cap_credits > 0
            && $experiment->budget_spent_credits >= $experiment->budget_cap_credits) {
            Log::warning('CheckBudgetAvailable: Experiment budget exceeded, skipping job', [
                'experiment_id' => $experiment->id,
                'spent' => $experiment->budget_spent_credits,
                'cap' => $experiment->budget_cap_credits,
                'job' => class_basename($job),
            ] + $crewContext);

            return;
        }

        // Check global team balance — only enforce if credits have been explicitly purchased.
        // Community/self-hosted installs never have purchase entries, so we skip this check
        // to avoid blocking jobs on deployments where no billing is configured.
        // teamHasPurchasedCredits() uses withoutGlobalScopes() so this works in queue
        // workers (console context) and tests.
        if (! CreditLedger::teamHasPurchasedCredits($teamId)) {
            $next($job);

            return;
        }

        // Secondary sort by id (UUIDv7 is time-ordered) ensures deterministic ordering
        // when multiple entries share the same created_at timestamp (e.g. in tests).
        $latestEntry = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['balance_after']);

        if ($latestEntry !== null && $latestEntry->balance_after <= 0) {
            Log::warning('CheckBudgetAvailable: User has no credits, skipping job', [
                'experiment_id' => $experiment?->id,
                'user_id' => $experiment?->user_id,
                'team_id' => $teamId,
                'balance' => $latestEntry->balance_after,
                'job' => class_basename($job),
            ] + $crewContext);

            return;
        }

        $next($job);
    }
}
