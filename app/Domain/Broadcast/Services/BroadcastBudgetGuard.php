<?php

namespace App\Domain\Broadcast\Services;

use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Budget\Models\CreditLedger;

/**
 * Pre-send budget gate for broadcasts.
 *
 * Enforces a hard recipient cap and, for teams that have credit history,
 * a balance check. Teams with no ledger entries (self-hosted installs) skip
 * the balance check — mirroring CheckBudgetAction's behaviour.
 */
class BroadcastBudgetGuard
{
    /** Maximum recipients a single broadcast may target. */
    public const MAX_RECIPIENTS = 5000;

    /** Credits charged per delivered email (1 credit = $0.001). */
    private const CREDIT_COST_PER_EMAIL = 1;

    /**
     * @throws InsufficientBudgetException when the broadcast cannot be afforded
     */
    public function assertCanSend(string $teamId, int $recipientCount): void
    {
        if ($recipientCount < 1) {
            throw new InsufficientBudgetException('Broadcast has no subscribed recipients.');
        }

        if ($recipientCount > self::MAX_RECIPIENTS) {
            throw new InsufficientBudgetException(
                "Broadcast exceeds the {$recipientCount}-recipient request against the "
                .self::MAX_RECIPIENTS.'-recipient cap.',
            );
        }

        $latest = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->latest()
            ->first();

        if ($latest === null) {
            return;
        }

        $estimatedCost = $recipientCount * self::CREDIT_COST_PER_EMAIL;
        if ($latest->balance_after < $estimatedCost) {
            throw new InsufficientBudgetException(
                "Insufficient credits for {$recipientCount} recipients: "
                ."need {$estimatedCost}, balance is {$latest->balance_after}.",
            );
        }
    }
}
