<?php

namespace App\Domain\Signal\Services;

use App\Domain\Signal\Models\CompanyIntentScore;
use App\Domain\Signal\Models\Signal;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Signal Stacking Engine — computes composite buyer intent scores.
 *
 * Implements the FIRE model (Fit + Intent + Engagement + Relationship)
 * with two key adjustments from Bombora's scoring methodology:
 *
 * 1. **Logarithmic stacking**: Multiple weaker signals beat a single
 *    strong signal. Formula: score * (1 + 0.3 * log(signal_count))
 *
 * 2. **Exponential decay**: Signals lose relevance over time.
 *    Formula: score * exp(-0.05 * days_old)  → ~22% retained at 30 days
 *
 * @see https://bombora.com/core-concepts/how-to-score-prioritize-accounts-leads-b2b/
 */
class SignalStackingEngine
{
    /**
     * Score weight by source type (0.0–1.0 multiplier).
     * Higher = more trusted / intentional signal.
     */
    private const SOURCE_WEIGHTS = [
        'clearcue'    => 1.0,  // verified AI-qualified buyer intent
        'manual'      => 0.8,  // human-curated, high confidence
        'github'      => 0.7,  // technical evaluation signal
        'linear'      => 0.6,  // issue/project activity
        'jira'        => 0.6,  // issue/project activity
        'webhook'     => 0.5,  // generic push, unknown quality
        'rss'         => 0.4,  // content engagement
        'sentry'      => 0.3,  // error/alert (weak intent signal)
        'intent_score' => 0.0, // synthetic — not counted to avoid recursion
    ];

    /**
     * Base score (0–100) by ClearCue signal category.
     * Maps to the four FIRE intent tiers.
     */
    private const CATEGORY_BASE_SCORES = [
        'purchase_intent' => 80,  // demo request, pricing page view
        'evaluation'      => 55,  // competitor research, job posting
        'research'        => 30,  // content consumption, news reading
        'hiring'          => 40,  // job posting for relevant role
        'social'          => 15,  // LinkedIn engagement
        'events'          => 20,  // conference RSVP
        'news'            => 25,  // press mention
        'weak_indicator'  => 8,   // social follow, newsletter open
    ];

    /**
     * Ghost job signal confidence discount.
     * LinkedIn job postings have a high false-positive rate ("ghost jobs").
     */
    private const HIRING_CONFIDENCE_FACTOR = 0.7;

    /**
     * Decay constant λ for exp(-λ * days_old).
     * At 14 days: ~50% retained. At 30 days: ~22% retained.
     */
    private const DECAY_LAMBDA = 0.05;

    /**
     * Recalculate the composite intent score for an entity.
     * Fetches all signals for the entity from the last 90 days.
     *
     * @param  string  $teamId
     * @param  string  $entityKey  LinkedIn URL, company domain, or any stable identifier
     * @param  string  $entityType 'company' | 'person'
     */
    public function recalculate(string $teamId, string $entityKey, string $entityType): CompanyIntentScore
    {
        // Fetch signals for this entity (last 90 days, all sources except intent_score itself)
        $signals = Signal::where('team_id', $teamId)
            ->where('source_identifier', $entityKey)
            ->where('source_type', '!=', 'intent_score')
            ->where('received_at', '>=', now()->subDays(90))
            ->get();

        $intentScore      = $this->computeIntentScore($signals);
        $engagementScore  = $this->computeEngagementScore($signals);
        $signalCount      = $signals->count();
        $signalDiversity  = $signals->pluck('source_type')->unique()->count();

        // FIRE model: Fit is not auto-computed (requires CRM data), defaults to 50
        // Relationship score defaults to 0 (no existing relationship data without CRM)
        $fitScore          = 50.0;
        $relationshipScore = 0.0;

        // Composite: weighted average of FIRE dimensions
        $composite = ($fitScore * 0.25)
            + ($intentScore * 0.40)
            + ($engagementScore * 0.25)
            + ($relationshipScore * 0.10);

        // Apply logarithmic stacking bonus for signal diversity
        if ($signalCount > 1) {
            $composite = $this->applyStackingMultiplier($composite, $signalCount);
        }

        $composite = min(round($composite, 2), 100.0);

        $breakdown = [
            'fit_score'         => round($fitScore, 2),
            'intent_score'      => round($intentScore, 2),
            'engagement_score'  => round($engagementScore, 2),
            'relationship_score' => round($relationshipScore, 2),
            'signal_count'      => $signalCount,
            'signal_diversity'  => $signalDiversity,
            'sources'           => $signals->pluck('source_type')->unique()->values()->all(),
            'computed_at'       => now()->toIso8601String(),
        ];

        return CompanyIntentScore::updateOrCreate(
            [
                'team_id'     => $teamId,
                'entity_key'  => $entityKey,
                'entity_type' => $entityType,
            ],
            [
                'composite_score'    => $composite,
                'fit_score'          => $fitScore,
                'intent_score'       => round($intentScore, 2),
                'engagement_score'   => round($engagementScore, 2),
                'relationship_score' => $relationshipScore,
                'signal_count'       => $signalCount,
                'signal_diversity'   => $signalDiversity,
                'score_breakdown'    => $breakdown,
                'last_scored_at'     => now(),
                'recalculate_after'  => now()->addMinutes(15), // debounce
            ]
        );
    }

    /**
     * Compute intent score from signals (0–100).
     * Uses category base scores with decay and source weight adjustments.
     */
    private function computeIntentScore(Collection $signals): float
    {
        if ($signals->isEmpty()) {
            return 0.0;
        }

        $intentSignals = $signals->filter(
            fn ($s) => in_array($s->source_type, ['clearcue', 'manual', 'webhook'], true)
        );

        if ($intentSignals->isEmpty()) {
            return 0.0;
        }

        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($intentSignals as $signal) {
            $category  = $signal->payload['signal_category'] ?? 'weak_indicator';
            $baseScore = self::CATEGORY_BASE_SCORES[$category] ?? 8;

            // Apply ghost job discount for hiring-only signals
            if ($category === 'hiring') {
                $baseScore *= self::HIRING_CONFIDENCE_FACTOR;
            }

            $sourceWeight = self::SOURCE_WEIGHTS[$signal->source_type] ?? 0.5;
            $decayedScore = $this->applyDecay($baseScore, $signal->received_at);

            $weightedSum  += $decayedScore * $sourceWeight;
            $totalWeight  += $sourceWeight;
        }

        return $totalWeight > 0 ? min($weightedSum / $totalWeight, 100.0) : 0.0;
    }

    /**
     * Compute engagement score from signals (0–100).
     * Based on signal frequency and recency across all sources.
     */
    private function computeEngagementScore(Collection $signals): float
    {
        if ($signals->isEmpty()) {
            return 0.0;
        }

        // Recency-weighted count: recent signals count more
        $score = 0.0;
        foreach ($signals as $signal) {
            $daysOld = Carbon::parse($signal->received_at)->diffInDays(now());
            $recencyBoost = max(1 - ($daysOld / 90), 0.1);

            // Frequency multiplier from signal payload (ClearCue provides signal_frequency)
            $frequency = min((int) ($signal->payload['signal_frequency'] ?? 1), 10);

            $score += $recencyBoost * $frequency * 5;
        }

        return min($score, 100.0);
    }

    /**
     * Apply exponential decay to a score based on signal age.
     * score * exp(-λ * days_old)
     */
    private function applyDecay(float $score, mixed $receivedAt): float
    {
        $daysOld = Carbon::parse($receivedAt)->diffInDays(now());

        return $score * exp(-self::DECAY_LAMBDA * $daysOld);
    }

    /**
     * Apply logarithmic stacking multiplier for signal diversity.
     * base * (1 + 0.3 * log(signal_count))
     *
     * This rewards accounts that show multiple signals across different
     * sources over single high-intensity signals (signal diversity = intent).
     */
    private function applyStackingMultiplier(float $baseScore, int $signalCount): float
    {
        $multiplier = 1 + 0.3 * log($signalCount);

        return min($baseScore * $multiplier, 100.0);
    }
}
