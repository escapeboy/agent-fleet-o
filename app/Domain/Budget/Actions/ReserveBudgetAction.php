<?php

namespace App\Domain\Budget\Actions;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\DB;

class ReserveBudgetAction
{
    /**
     * Reserve credits for an upcoming AI call.
     * Uses pessimistic locking to prevent over-reservation.
     *
     * @return CreditLedger The reservation ledger entry
     *
     * @throws InsufficientBudgetException
     */
    public function execute(
        string $userId,
        string $teamId,
        int $amount,
        ?string $experimentId = null,
        string $description = 'Budget reservation',
    ): CreditLedger {
        return DB::transaction(function () use ($userId, $teamId, $amount, $experimentId, $description) {
            // Check experiment budget cap
            if ($experimentId) {
                $experiment = Experiment::withoutGlobalScopes()->lockForUpdate()->find($experimentId);

                if ($experiment && $experiment->budget_cap_credits > 0) {
                    $remaining = $experiment->budget_cap_credits - $experiment->budget_spent_credits;

                    if ($amount > $remaining) {
                        throw new InsufficientBudgetException(
                            "Experiment budget exceeded. Remaining: {$remaining} credits, requested: {$amount} credits.",
                        );
                    }
                }
            }

            // Get current balance with lock (scoped by team)
            $lastEntry = CreditLedger::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->first();

            $currentBalance = $lastEntry?->balance_after ?? 0;

            if ($amount > $currentBalance) {
                throw new InsufficientBudgetException(
                    "Insufficient credits. Balance: {$currentBalance}, requested: {$amount}.",
                );
            }

            return CreditLedger::withoutGlobalScopes()->create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'experiment_id' => $experimentId,
                'type' => LedgerType::Reservation,
                'amount' => -$amount,
                'balance_after' => $currentBalance - $amount,
                'description' => $description,
                'metadata' => ['reserved_amount' => $amount],
            ]);
        });
    }
}
