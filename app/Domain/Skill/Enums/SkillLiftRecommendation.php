<?php

namespace App\Domain\Skill\Enums;

/**
 * Recommendation derived from a skill's lift (with-skill minus without-skill judge score).
 * Borrowed from ZooEval's recommendation tiers; thresholds configurable via
 * config('skills.lift_eval.thresholds').
 */
enum SkillLiftRecommendation: string
{
    case HighlyRecommended = 'highly_recommended';
    case Recommended = 'recommended';
    case Conditional = 'conditional';
    case Marginal = 'marginal';
    case Harmful = 'harmful';

    public static function fromDelta(float $delta): self
    {
        $t = config('skills.lift_eval.thresholds', [
            'highly' => 1.5,
            'recommended' => 0.5,
            'conditional' => 0.1,
        ]);

        return match (true) {
            $delta >= $t['highly'] => self::HighlyRecommended,
            $delta >= $t['recommended'] => self::Recommended,
            $delta >= $t['conditional'] => self::Conditional,
            $delta >= -$t['conditional'] => self::Marginal,
            default => self::Harmful,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::HighlyRecommended => 'Highly Recommended',
            self::Recommended => 'Recommended',
            self::Conditional => 'Conditional',
            self::Marginal => 'Marginal',
            self::Harmful => 'Harmful',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::HighlyRecommended => 'green',
            self::Recommended => 'emerald',
            self::Conditional => 'amber',
            self::Marginal => 'gray',
            self::Harmful => 'red',
        };
    }
}
