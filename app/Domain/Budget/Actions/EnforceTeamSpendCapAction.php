<?php

namespace App\Domain\Budget\Actions;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pre-call enforcement of the optional team-wide AI spend limit
 * (teams.settings.spend_cap_daily_credits / spend_cap_monthly_credits).
 *
 * Spend is the sum of AiRun.cost_credits in the current calendar day / month.
 * Throws InsufficientBudgetException when the call would push the team past a
 * limit. The team owner is notified once per period at 80% and once when the
 * limit is reached.
 */
class EnforceTeamSpendCapAction
{
    private const WARN_RATIO = 0.8;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function execute(string $teamId, int $estimatedCost): void
    {
        if ($teamId === '') {
            return;
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! $team) {
            return;
        }

        $settings = $team->settings ?? [];

        $periods = [
            'daily' => [$this->capFrom($settings['spend_cap_daily_credits'] ?? null), now()->startOfDay(), now()->endOfDay(), now()->format('Y-m-d')],
            'monthly' => [$this->capFrom($settings['spend_cap_monthly_credits'] ?? null), now()->startOfMonth(), now()->endOfMonth(), now()->format('Y-m')],
        ];

        foreach ($periods as $period => [$cap, $start, $end, $periodKey]) {
            if ($cap === null) {
                continue;
            }

            $spent = (int) AiRun::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('created_at', '>=', $start)
                ->sum('cost_credits');

            $projected = $spent + $estimatedCost;

            if ($projected > $cap) {
                $this->notifyOnce(
                    teamId: $teamId,
                    cacheKey: "team-spend-cap:{$teamId}:{$period}:{$periodKey}:block",
                    expiresAt: $end,
                    type: 'budget.exceeded',
                    title: 'Team spend limit reached',
                    body: "Your team reached its {$period} AI spend limit ({$spent} of {$cap} credits). AI calls are paused until the period resets or the limit is raised.",
                    data: ['period' => $period, 'spent' => $spent, 'cap' => $cap],
                );

                throw new InsufficientBudgetException(
                    "Team {$period} spend limit reached: {$spent} of {$cap} credits used. ".
                    'Raise the limit in Team settings or wait for the period to reset.',
                );
            }

            if ($projected / $cap >= self::WARN_RATIO) {
                $this->notifyOnce(
                    teamId: $teamId,
                    cacheKey: "team-spend-cap:{$teamId}:{$period}:{$periodKey}:warn",
                    expiresAt: $end,
                    type: 'usage.alert',
                    title: 'Team spend at 80% of limit',
                    body: "Your team has used {$spent} of its {$cap} credit {$period} AI spend limit.",
                    data: ['period' => $period, 'spent' => $spent, 'cap' => $cap],
                );
            }
        }
    }

    private function capFrom(mixed $value): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $cap = (int) $value;

        return $cap > 0 ? $cap : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifyOnce(
        string $teamId,
        string $cacheKey,
        CarbonInterface $expiresAt,
        string $type,
        string $title,
        string $body,
        array $data,
    ): void {
        try {
            $ownerId = Team::ownerIdFor($teamId);
            if (! $ownerId) {
                return;
            }

            if (! Cache::add($cacheKey, 1, $expiresAt)) {
                return;
            }

            $this->notifications->notify(
                userId: $ownerId,
                teamId: $teamId,
                type: $type,
                title: $title,
                body: $body,
                actionUrl: '/team',
                data: $data,
            );
        } catch (\Throwable $e) {
            Log::warning('EnforceTeamSpendCapAction: notification failed', [
                'team_id' => $teamId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
