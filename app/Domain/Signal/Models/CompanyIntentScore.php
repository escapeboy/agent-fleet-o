<?php

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Composite buyer intent score for a company or person.
 *
 * Aggregates signals from multiple sources using the FIRE model
 * (Fit + Intent + Engagement + Relationship) with logarithmic signal
 * stacking and exponential decay for signal age.
 *
 * Updated asynchronously by RecalculateIntentScoreJob whenever a new
 * signal arrives for the entity, debounced to once per 15 minutes.
 */
class CompanyIntentScore extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'entity_key',
        'entity_type',
        'composite_score',
        'fit_score',
        'intent_score',
        'engagement_score',
        'relationship_score',
        'signal_count',
        'signal_diversity',
        'score_breakdown',
        'last_scored_at',
        'recalculate_after',
    ];

    protected $casts = [
        'composite_score'    => 'float',
        'fit_score'          => 'float',
        'intent_score'       => 'float',
        'engagement_score'   => 'float',
        'relationship_score' => 'float',
        'score_breakdown'    => 'array',
        'last_scored_at'     => 'datetime',
        'recalculate_after'  => 'datetime',
    ];

    /**
     * The score was last calculated more than 15 minutes ago.
     */
    public function isStale(): bool
    {
        return $this->last_scored_at === null
            || $this->last_scored_at->lt(now()->subMinutes(15));
    }

    /**
     * Debounce window has passed — safe to recalculate.
     */
    public function needsRecalculation(): bool
    {
        return $this->recalculate_after === null
            || $this->recalculate_after->isPast();
    }

    /**
     * Return the intent classification tag based on composite_score.
     *
     * | 80–100 | intent.hot       — immediate action recommended |
     * | 50–79  | intent.warm      — BDR sequence                |
     * | 20–49  | intent.lukewarm  — nurture                     |
     * |  0–19  | intent.cold      — monitor only                |
     */
    public function intentTag(): string
    {
        return match (true) {
            $this->composite_score >= 80 => 'intent.hot',
            $this->composite_score >= 50 => 'intent.warm',
            $this->composite_score >= 20 => 'intent.lukewarm',
            default                      => 'intent.cold',
        };
    }
}
