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
     * @return CreditLedger|null The reservation ledger entry, or null when the team
     *                           has no billing configured (no purchased credits) and
     *                           credit enforcement is therefore skipped.
     *
     * @throws InsufficientBudgetException
     */
    public function execute(
        string $userId,
        string $teamId,
        int $amount,
        ?string $experimentId = null,
        string $description = 'Budget reservation',
    ): ?CreditLedger {
        // Skip credit enforcement entirely for teams without billing configured.
        // Community/self-hosted installs and not-yet-billed teams never have purchase
        // entries, so reserving against a zero balance would wrongly block their
        // BYOK and platform-funded calls. Mirrors CheckBudgetAction / CheckBudgetAvailable.
        if (! CreditLedger::teamHasPurchasedCredits($teamId)) {
            return null;
        }

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

            // Get current balance with lock (scoped by team). Secondary sort by id
            // (UUIDv7 is time-ordered) guarantees deterministic ordering when several
            // entries share the same created_at timestamp.
            $lastEntry = CreditLedger::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $currentBalance = $lastEntry->balance_after ?? 0;

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
