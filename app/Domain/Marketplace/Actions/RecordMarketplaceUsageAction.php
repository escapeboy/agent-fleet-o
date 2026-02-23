<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Models\MarketplaceInstallation;
use App\Domain\Marketplace\Models\MarketplaceUsageRecord;
use App\Domain\Skill\Models\SkillExecution;

class RecordMarketplaceUsageAction
{
    public function __construct(
        private readonly TransferMarketplaceCreditsAction $transferCredits,
    ) {}

    /**
     * Record a marketplace skill execution and optionally transfer credits.
     */
    public function execute(SkillExecution $execution): ?MarketplaceUsageRecord
    {
        // Find the installation that links this skill to a marketplace listing
        $installation = MarketplaceInstallation::withoutGlobalScopes()
            ->whereNotNull('installed_skill_id')
            ->where('installed_skill_id', $execution->skill_id)
            ->where('team_id', $execution->team_id)
            ->first();

        if (! $installation) {
            return null;
        }

        $listing = $installation->listing;

        if (! $listing) {
            return null;
        }

        $record = MarketplaceUsageRecord::create([
            'listing_id' => $listing->id,
            'installation_id' => $installation->id,
            'team_id' => $execution->team_id,
            'status' => $execution->status,
            'cost_credits' => $execution->cost_credits,
            'duration_ms' => $execution->duration_ms,
            'executed_at' => $execution->created_at,
        ]);

        // Transfer credits if paid listing and execution succeeded
        if ($listing->isPaid() && $execution->status === 'completed') {
            $this->transferCredits->execute($installation, $listing, $execution->team_id);
        }

        return $record;
    }
}
