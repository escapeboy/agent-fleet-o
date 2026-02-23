<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceUsageRecord;
use Illuminate\Support\Facades\DB;

class AggregateMarketplaceStatsAction
{
    /**
     * Recalculate aggregate stats on marketplace_listings from usage records.
     * Called by metrics:aggregate command.
     */
    public function execute(): int
    {
        $listings = MarketplaceListing::withoutGlobalScopes()
            ->whereHas('usageRecords')
            ->get();

        foreach ($listings as $listing) {
            $stats = MarketplaceUsageRecord::withoutGlobalScopes()
                ->where('listing_id', $listing->id)
                ->selectRaw('
                    COUNT(*) as total_runs,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success_runs,
                    AVG(cost_credits) as avg_cost,
                    AVG(duration_ms) as avg_duration
                ', ['completed'])
                ->first();

            // Build 12-month usage trend
            $trend = MarketplaceUsageRecord::withoutGlobalScopes()
                ->where('listing_id', $listing->id)
                ->selectRaw("TO_CHAR(executed_at, 'YYYY-MM') as period, COUNT(*) as runs")
                ->where('executed_at', '>=', now()->subMonths(12))
                ->groupBy(DB::raw("TO_CHAR(executed_at, 'YYYY-MM')"))
                ->orderBy('period')
                ->get()
                ->map(fn ($r) => ['period' => $r->period, 'runs' => (int) $r->runs])
                ->toArray();

            $listing->withoutGlobalScopes()->update([
                'run_count' => (int) $stats->total_runs,
                'success_count' => (int) $stats->success_runs,
                'avg_cost_credits' => $stats->avg_cost ? round((float) $stats->avg_cost, 4) : null,
                'avg_duration_ms' => $stats->avg_duration ? round((float) $stats->avg_duration, 2) : null,
                'usage_trend' => $trend,
            ]);
        }

        return $listings->count();
    }
}
