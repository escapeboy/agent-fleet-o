<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Marketplace\Models\MarketplaceInstallation;
use App\Domain\Marketplace\Models\MarketplaceListing;
use Illuminate\Support\Facades\DB;

class TransferMarketplaceCreditsAction
{
    private const PLATFORM_FEE_RATE = 0.20;

    /**
     * Transfer credits from consuming team to publisher team.
     * Uses pessimistic locking on both teams to prevent race conditions.
     */
    public function execute(
        MarketplaceInstallation $installation,
        MarketplaceListing $listing,
        string $consumingTeamId,
    ): void {
        $amount = (float) $listing->price_per_run_credits;

        if ($amount <= 0) {
            return;
        }

        $publisherTeamId = $listing->team_id;
        $platformFee = round($amount * self::PLATFORM_FEE_RATE, 6);
        $publisherRevenue = round($amount - $platformFee, 6);

        DB::transaction(function () use (
            $consumingTeamId, $publisherTeamId, $installation,
            $listing, $amount, $platformFee, $publisherRevenue
        ) {
            // Lock and deduct from consuming team
            $consumerLast = CreditLedger::withoutGlobalScopes()
                ->where('team_id', $consumingTeamId)
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->first();

            $consumerBalance = (float) ($consumerLast?->balance_after ?? 0);

            // Skip silently if insufficient balance (already charged by regular budget)
            if ($amount > $consumerBalance) {
                return;
            }

            CreditLedger::withoutGlobalScopes()->create([
                'team_id' => $consumingTeamId,
                'type' => LedgerType::MarketplacePurchase,
                'amount' => -$amount,
                'balance_after' => $consumerBalance - $amount,
                'description' => "Marketplace run: {$listing->name}",
                'metadata' => [
                    'listing_id' => $listing->id,
                    'installation_id' => $installation->id,
                ],
            ]);

            // Credit publisher team (80% of price)
            $publisherLast = CreditLedger::withoutGlobalScopes()
                ->where('team_id', $publisherTeamId)
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->first();

            $publisherBalance = (float) ($publisherLast?->balance_after ?? 0);

            CreditLedger::withoutGlobalScopes()->create([
                'team_id' => $publisherTeamId,
                'type' => LedgerType::MarketplaceRevenue,
                'amount' => $publisherRevenue,
                'balance_after' => $publisherBalance + $publisherRevenue,
                'description' => "Marketplace revenue: {$listing->name}",
                'metadata' => [
                    'listing_id' => $listing->id,
                    'consuming_team_id' => $consumingTeamId,
                    'platform_fee' => $platformFee,
                ],
            ]);

            // Update installation totals
            $installation->withoutGlobalScopes()
                ->increment('total_credits_spent', $amount);
            $installation->withoutGlobalScopes()
                ->where('id', $installation->id)
                ->increment('total_revenue_earned', $publisherRevenue);
        });
    }
}
