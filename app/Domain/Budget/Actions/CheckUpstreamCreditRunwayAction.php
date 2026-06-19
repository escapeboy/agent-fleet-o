<?php

namespace App\Domain\Budget\Actions;

use App\Domain\Budget\Notifications\UpstreamCreditLowNotification;
use App\Domain\Budget\Services\UpstreamSpendForecaster;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Evaluates upstream credit runway per configured (sub_program, provider) budget
 * and emails the platform owner when the forecasted days-to-depletion crosses a
 * threshold. Runtime config (budgets/recipient/thresholds/cooldown) is read from
 * GlobalSetting first, falling back to config/credit_alerts.php.
 */
class CheckUpstreamCreditRunwayAction
{
    public function __construct(private readonly UpstreamSpendForecaster $forecaster) {}

    /**
     * @return array<int, array<string, mixed>> One forecast summary per evaluated budget.
     */
    public function execute(bool $dryRun = false): array
    {
        if (! (bool) config('credit_alerts.enabled', true)) {
            return [];
        }

        $budgets = $this->budgets();
        $thresholds = $this->thresholds();
        $recipient = $this->recipient();
        $cooldownHours = (int) (GlobalSetting::get('upstream_credit_alert_cooldown_hours')
            ?? config('credit_alerts.cooldown_hours', 24));

        $summaries = [];

        foreach ($budgets as $subProgram => $providers) {
            if (! is_array($providers)) {
                continue;
            }

            foreach ($providers as $provider => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $credits = (int) ($entry['credits'] ?? 0);
                if ($credits <= 0) {
                    continue;
                }
                $since = (string) ($entry['since'] ?? now()->subDays(30)->toDateString());

                $forecast = $this->forecaster->forecast((string) $subProgram, (string) $provider, $credits, $since);
                $bucket = $this->crossedBucket($forecast['days_until_depletion'], $thresholds);

                $alerted = false;
                if ($bucket !== null && $recipient && ! $dryRun
                    && $this->reserveAlertSlot((string) $subProgram, (string) $provider, $bucket, $cooldownHours)) {
                    Notification::route('mail', $recipient)->notify(new UpstreamCreditLowNotification(
                        subProgram: (string) $subProgram,
                        provider: (string) $provider,
                        remaining: $forecast['remaining'],
                        dailyAvg7d: $forecast['daily_avg_7d'],
                        daysUntilDepletion: $forecast['days_until_depletion'],
                        budgetCredits: $credits,
                    ));
                    $alerted = true;
                }

                unset($forecast['daily_series']);
                $summaries[] = $forecast + ['alert_bucket' => $bucket, 'alerted' => $alerted];
            }
        }

        return $summaries;
    }

    /**
     * The tightest threshold the runway is at or below (most critical), or null
     * when the runway is safe or cannot be computed.
     *
     * @param  array<int, int>  $thresholds
     */
    private function crossedBucket(?int $days, array $thresholds): ?int
    {
        if ($days === null) {
            return null;
        }

        sort($thresholds); // ascending: smallest (most critical) first
        foreach ($thresholds as $threshold) {
            if ($days <= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    /**
     * Atomically claim the alert slot for this (sub_program, provider, bucket).
     * Returns true the first time within the cooldown window, false thereafter,
     * so a smaller bucket (different key) can still alert immediately.
     */
    private function reserveAlertSlot(string $subProgram, string $provider, int $bucket, int $cooldownHours): bool
    {
        $key = "upstream_credit_alert:{$subProgram}:{$provider}:{$bucket}";

        return Cache::add($key, true, now()->addHours(max(1, $cooldownHours)));
    }

    /**
     * Budgets may come from GlobalSetting (untrusted runtime JSON), so the inner
     * shape is validated per-entry by the caller rather than asserted here.
     *
     * @return array<string, mixed>
     */
    private function budgets(): array
    {
        $override = GlobalSetting::get('upstream_credit_budgets');

        return is_array($override) ? $override : (array) config('credit_alerts.budgets', []);
    }

    /**
     * @return array<int, int>
     */
    private function thresholds(): array
    {
        $override = GlobalSetting::get('upstream_credit_alert_threshold_days');
        $values = is_array($override) ? $override : (array) config('credit_alerts.threshold_days', [14, 7, 3]);

        return array_values(array_map('intval', $values));
    }

    private function recipient(): ?string
    {
        $value = GlobalSetting::get('upstream_credit_alert_email') ?? config('credit_alerts.recipient');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
