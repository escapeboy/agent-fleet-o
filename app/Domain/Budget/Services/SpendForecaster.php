<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Models\CreditLedger;
use App\Models\GlobalSetting;

class SpendForecaster
{
    /**
     * Calculate daily spend for the past N days and project forward.
     *
     * Returns an array with:
     *   - daily_avg_7d: average daily spend over last 7 days
     *   - daily_avg_30d: average daily spend over last 30 days
     *   - projected_30d: projected spend over the next 30 days (using 7d avg)
     *   - days_until_cap: null if no cap or if cap is safe; integer if cap will be hit
     *   - trend: 'up' | 'stable' | 'down' (compares 7d avg to 30d avg)
     *   - daily_series: array of ['date' => ..., 'spend' => ...] for the last 30 days
     *   - budget_cap: global budget cap in credits (0 = unlimited)
     *   - total_spent: total credits spent to date
     */
    public function forecast(): array
    {
        $budgetCap = (int) GlobalSetting::get('global_budget_cap', 0);
        $totalSpent = abs((int) CreditLedger::withoutGlobalScopes()->where('type', 'spend')->sum('amount'));

        // Get daily spend for the past 30 days
        $since30 = now()->subDays(30)->startOfDay();
        $since7 = now()->subDays(7)->startOfDay();

        $dailySeries = CreditLedger::withoutGlobalScopes()
            ->where('type', 'spend')
            ->where('created_at', '>=', $since30)
            ->selectRaw('DATE(created_at) as day, ABS(SUM(amount)) as spend')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        // Fill missing days with 0
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $series[] = [
                'date' => $date,
                'spend' => (int) ($dailySeries[$date]->spend ?? 0),
            ];
        }

        // 7-day average
        $last7 = collect($series)->slice(-7);
        $avg7 = $last7->avg('spend');

        // 30-day average
        $avg30 = collect($series)->avg('spend');

        // Trend comparison
        $trend = 'stable';
        if ($avg30 > 0) {
            $ratio = $avg7 / $avg30;
            if ($ratio >= 1.15) {
                $trend = 'up';
            } elseif ($ratio <= 0.85) {
                $trend = 'down';
            }
        }

        // Projection for next 30 days
        $projected30d = (int) round($avg7 * 30);

        // Days until budget cap is hit
        $daysUntilCap = null;
        if ($budgetCap > 0 && $avg7 > 0) {
            $remaining = max(0, $budgetCap - $totalSpent);
            $daysUntilCap = $remaining > 0 ? (int) ceil($remaining / $avg7) : 0;
        }

        return [
            'daily_avg_7d' => (int) round($avg7),
            'daily_avg_30d' => (int) round($avg30),
            'projected_30d' => $projected30d,
            'days_until_cap' => $daysUntilCap,
            'trend' => $trend,
            'daily_series' => $series,
            'budget_cap' => $budgetCap,
            'total_spent' => $totalSpent,
        ];
    }
}
