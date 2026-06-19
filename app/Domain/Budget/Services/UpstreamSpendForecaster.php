<?php

namespace App\Domain\Budget\Services;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Illuminate\Support\Carbon;

/**
 * Forecasts upstream (platform-funded) credit runway for a single
 * (sub_program, provider) pair, from LlmRequestLog spend.
 *
 * "Platform-funded" = requests served by a platform/sub-program API key
 * (byok_source = 'platform'), as opposed to a team's own BYOK key.
 */
class UpstreamSpendForecaster
{
    /**
     * @return array{
     *   sub_program: string,
     *   provider: string,
     *   budget_credits: int,
     *   since: string,
     *   spent_since: int,
     *   remaining: int,
     *   daily_avg_7d: int,
     *   daily_avg_30d: int,
     *   days_until_depletion: int|null,
     *   daily_series: array<int, array{date: string, spend: int}>
     * }
     */
    public function forecast(string $subProgram, string $provider, int $budgetCredits, string $since): array
    {
        $sinceDate = Carbon::parse($since)->startOfDay();
        $window30 = now()->subDays(30)->startOfDay();

        $teamIds = Team::withoutGlobalScopes()
            ->where('sub_program_slug', $subProgram)
            ->pluck('id');

        $base = fn () => LlmRequestLog::withoutGlobalScopes()
            ->whereIn('team_id', $teamIds)
            ->where('provider', $provider)
            ->where('byok_source', 'platform');

        if ($teamIds->isEmpty()) {
            return $this->emptyResult($subProgram, $provider, $budgetCredits, $sinceDate->toDateString());
        }

        $dailySpend = $base()
            ->where('completed_at', '>=', $window30)
            ->selectRaw('DATE(completed_at) as day, SUM(cost_credits) as spend')
            ->groupByRaw('DATE(completed_at)')
            ->pluck('spend', 'day');

        // Fill the 30-day window with zeros for missing days.
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $series[] = ['date' => $date, 'spend' => (int) ($dailySpend[$date] ?? 0)];
        }

        $avg7 = collect($series)->slice(-7)->avg('spend') ?? 0.0;
        $avg30 = collect($series)->avg('spend') ?? 0.0;

        $spentSince = (int) $base()->where('completed_at', '>=', $sinceDate)->sum('cost_credits');
        $remaining = max(0, $budgetCredits - $spentSince);

        $daysUntilDepletion = null;
        if ($avg7 > 0) {
            $daysUntilDepletion = $remaining > 0 ? (int) ceil($remaining / $avg7) : 0;
        }

        return [
            'sub_program' => $subProgram,
            'provider' => $provider,
            'budget_credits' => $budgetCredits,
            'since' => $sinceDate->toDateString(),
            'spent_since' => $spentSince,
            'remaining' => $remaining,
            'daily_avg_7d' => (int) round($avg7),
            'daily_avg_30d' => (int) round($avg30),
            'days_until_depletion' => $daysUntilDepletion,
            'daily_series' => $series,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $subProgram, string $provider, int $budgetCredits, string $since): array
    {
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $series[] = ['date' => now()->subDays($i)->format('Y-m-d'), 'spend' => 0];
        }

        return [
            'sub_program' => $subProgram,
            'provider' => $provider,
            'budget_credits' => $budgetCredits,
            'since' => $since,
            'spent_since' => 0,
            'remaining' => max(0, $budgetCredits),
            'daily_avg_7d' => 0,
            'daily_avg_30d' => 0,
            'days_until_depletion' => null,
            'daily_series' => $series,
        ];
    }
}
