<?php

namespace App\Jobs\Middleware;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;
use Closure;
use Illuminate\Support\Facades\Log;

class CheckBudgetAvailable
{
    public function handle(object $job, Closure $next): void
    {
        if (! property_exists($job, 'experimentId')) {
            $next($job);

            return;
        }

        $experiment = Experiment::find($job->experimentId);

        if (! $experiment) {
            $next($job);

            return;
        }

        // Check experiment budget cap
        if ($experiment->budget_cap_credits > 0
            && $experiment->budget_spent_credits >= $experiment->budget_cap_credits) {
            Log::warning('CheckBudgetAvailable: Experiment budget exceeded, skipping job', [
                'experiment_id' => $experiment->id,
                'spent' => $experiment->budget_spent_credits,
                'cap' => $experiment->budget_cap_credits,
                'job' => class_basename($job),
            ]);

            return;
        }

        // Check global team balance — only enforce if credits have been explicitly purchased.
        // Community/self-hosted installs never have purchase entries, so we skip this check
        // to avoid blocking jobs on deployments where no billing is configured.
        // withoutGlobalScopes() ensures this works in queue workers (console context) and tests.
        $hasPurchasedCredits = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->whereIn('type', [LedgerType::Purchase->value, LedgerType::Refund->value])
            ->exists();

        if (! $hasPurchasedCredits) {
            $next($job);

            return;
        }

        // Secondary sort by id (UUIDv7 is time-ordered) ensures deterministic ordering
        // when multiple entries share the same created_at timestamp (e.g. in tests).
        $latestEntry = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['balance_after']);

        if ($latestEntry !== null && $latestEntry->balance_after <= 0) {
            Log::warning('CheckBudgetAvailable: User has no credits, skipping job', [
                'experiment_id' => $experiment->id,
                'user_id' => $experiment->user_id,
                'balance' => $latestEntry->balance_after,
                'job' => class_basename($job),
            ]);

            return;
        }

        $next($job);
    }
}
