<?php

namespace App\Domain\Budget\Actions;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\DB;

class SettleBudgetAction
{
    /**
     * Settle a budget reservation against actual cost.
     * If actual < reserved, release the difference.
     * If actual > reserved, deduct the difference.
     */
    public function execute(
        CreditLedger $reservation,
        int $actualCost,
        ?string $aiRunId = null,
    ): void {
        DB::transaction(function () use ($reservation, $actualCost, $aiRunId) {
            $reservedAmount = abs($reservation->amount);
            $difference = $reservedAmount - $actualCost;

            $lastEntry = CreditLedger::withoutGlobalScopes()
                ->where('team_id', $reservation->team_id)
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->first();

            $currentBalance = $lastEntry->balance_after;

            if ($difference > 0) {
                // Release excess reservation
                CreditLedger::withoutGlobalScopes()->create([
                    'team_id' => $reservation->team_id,
                    'user_id' => $reservation->user_id,
                    'experiment_id' => $reservation->experiment_id,
                    'ai_run_id' => $aiRunId,
                    'type' => LedgerType::Release,
                    'amount' => $difference,
                    'balance_after' => $currentBalance + $difference,
                    'description' => "Released excess reservation ({$difference} credits)",
                    'metadata' => [
                        'reservation_id' => $reservation->id,
                        'reserved' => $reservedAmount,
                        'actual' => $actualCost,
                    ],
                ]);
            } elseif ($difference < 0) {
                // Need additional deduction
                $extra = abs($difference);

                CreditLedger::withoutGlobalScopes()->create([
                    'team_id' => $reservation->team_id,
                    'user_id' => $reservation->user_id,
                    'experiment_id' => $reservation->experiment_id,
                    'ai_run_id' => $aiRunId,
                    'type' => LedgerType::Deduction,
                    'amount' => -$extra,
                    'balance_after' => $currentBalance - $extra,
                    'description' => "Additional cost beyond reservation ({$extra} credits)",
                    'metadata' => [
                        'reservation_id' => $reservation->id,
                        'reserved' => $reservedAmount,
                        'actual' => $actualCost,
                    ],
                ]);
            }

            // Update experiment spend tracking
            if ($reservation->experiment_id) {
                Experiment::withoutGlobalScopes()
                    ->where('id', $reservation->experiment_id)
                    ->increment('budget_spent_credits', $actualCost);
            }
        });
    }
}
