<?php

namespace App\Console\Commands\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceInstallation;
use App\Domain\Marketplace\Models\MarketplaceListing;
use Illuminate\Console\Command;

class AggregateMarketplaceQualityCommand extends Command
{
    protected $signature = 'marketplace:aggregate-quality
                            {--listing= : Aggregate for a specific listing ID only}';

    protected $description = 'Aggregate skill quality metrics from installations into marketplace listings';

    public function handle(): int
    {
        $query = MarketplaceListing::query()->withoutGlobalScopes()->where('install_count', '>', 0);

        if ($listingId = $this->option('listing')) {
            $query->where('id', $listingId);
        }

        $updated = 0;

        $query->each(function (MarketplaceListing $listing) use (&$updated) {
            // Load all installations for this listing and aggregate their skill metrics
            $installations = MarketplaceInstallation::query()
                ->withoutGlobalScopes()
                ->where('listing_id', $listing->id)
                ->whereNotNull('installed_skill_id')
                ->with('installedSkill')
                ->get();

            if ($installations->isEmpty()) {
                return;
            }

            $totalApplied = 0;
            $totalCompleted = 0;
            $totalEffective = 0;

            foreach ($installations as $installation) {
                $skill = $installation->installedSkill;
                if (! $skill) {
                    continue;
                }

                $totalApplied += $skill->applied_count;
                $totalCompleted += $skill->completed_count;
                $totalEffective += $skill->effective_count;
            }

            if ($totalApplied === 0) {
                return;
            }

            $installSuccessRate = round($totalEffective / $totalApplied, 4);
            $communityReliabilityRate = round($totalCompleted / $totalApplied, 4);
            $avgRating = (float) ($listing->avg_rating ?? 0);
            $normalizedRating = $avgRating > 0 ? $avgRating / 5.0 : 0.5;

            $communityQualityScore = round(
                ($installSuccessRate * 0.4) + ($communityReliabilityRate * 0.4) + ($normalizedRating * 0.2),
                4,
            );

            $listing->update([
                'install_success_rate' => $installSuccessRate,
                'community_reliability_rate' => $communityReliabilityRate,
                'effective_run_count' => $totalEffective,
                'community_quality_score' => $communityQualityScore,
                'quality_computed_at' => now(),
            ]);

            $updated++;
        });

        $this->info("Aggregated quality for {$updated} marketplace listing(s).");

        return self::SUCCESS;
    }
}
