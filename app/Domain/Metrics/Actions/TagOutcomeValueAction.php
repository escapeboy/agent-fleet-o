<?php

namespace App\Domain\Metrics\Actions;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Models\Metric;
use App\Domain\Metrics\Services\RocsCalculator;
use Illuminate\Support\Facades\Log;

/**
 * Tag an experiment with realised business value (an outcome "receipt").
 *
 * Complements {@see AttributeRevenueAction} (Stripe-driven `payment` metrics)
 * by letting an agent or human record value an automated payment hook would
 * never see — a closed deal, a booked meeting, a resolved ticket. The metric
 * feeds {@see RocsCalculator} as ROI value.
 *
 * Value is stored in cents to match `payment` metrics so both sum cleanly.
 */
class TagOutcomeValueAction
{
    private const VALID_OUTCOMES = ['success', 'partial', 'failure'];

    /**
     * @param  float  $valueUsd  Realised value in USD (stored as cents).
     * @param  string|null  $outcome  One of success|partial|failure (others ignored).
     */
    public function execute(
        string $experimentId,
        float $valueUsd,
        string $teamId,
        ?string $outcome = null,
        ?string $note = null,
        string $source = 'manual',
    ): ?Metric {
        $belongs = Experiment::withoutGlobalScopes()
            ->where('id', $experimentId)
            ->where('team_id', $teamId)
            ->exists();

        if (! $belongs) {
            Log::warning('TagOutcomeValueAction: experiment does not belong to team', [
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
            ]);

            return null;
        }

        $normalizedOutcome = in_array($outcome, self::VALID_OUTCOMES, true) ? $outcome : null;

        $metric = Metric::create([
            'team_id' => $teamId,
            'experiment_id' => $experimentId,
            'type' => 'business_value',
            'value' => round($valueUsd * 100, 2),
            'source' => $source,
            'metadata' => array_filter([
                'outcome' => $normalizedOutcome,
                'note' => $note,
                'value_usd' => $valueUsd,
            ], fn ($v) => $v !== null),
            'occurred_at' => now(),
            'recorded_at' => now(),
        ]);

        Log::info('Outcome value tagged', [
            'metric_id' => $metric->id,
            'experiment_id' => $experimentId,
            'value_usd' => $valueUsd,
            'outcome' => $normalizedOutcome,
        ]);

        return $metric;
    }
}
