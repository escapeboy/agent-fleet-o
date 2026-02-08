<?php

namespace App\Domain\Budget\Actions;

use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;

class CheckBudgetAction
{
    /**
     * Check if an experiment (and its owner) has sufficient budget to continue.
     *
     * @return array{ok: bool, reason: ?string, pct_used: float}
     */
    public function execute(Experiment $experiment): array
    {
        // Per-experiment budget cap check
        if ($experiment->budget_cap_credits > 0) {
            $pctUsed = ($experiment->budget_spent_credits / $experiment->budget_cap_credits) * 100;

            if ($experiment->budget_spent_credits >= $experiment->budget_cap_credits) {
                return [
                    'ok' => false,
                    'reason' => 'Experiment budget cap reached',
                    'pct_used' => min(100, $pctUsed),
                ];
            }

            if ($pctUsed >= 80) {
                return [
                    'ok' => true,
                    'reason' => 'Budget warning: ' . round($pctUsed, 1) . '% used',
                    'pct_used' => $pctUsed,
                ];
            }
        }

        // Global team balance check (scoped by team_id for isolation)
        $balance = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->orderByDesc('created_at')
            ->value('balance_after') ?? 0;

        if ($balance <= 0) {
            return [
                'ok' => false,
                'reason' => 'User has no remaining credits',
                'pct_used' => 100,
            ];
        }

        return [
            'ok' => true,
            'reason' => null,
            'pct_used' => $experiment->budget_cap_credits > 0
                ? ($experiment->budget_spent_credits / $experiment->budget_cap_credits) * 100
                : 0,
        ];
    }
}
