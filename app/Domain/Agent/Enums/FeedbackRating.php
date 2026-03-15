<?php

namespace App\Domain\Agent\Enums;

enum FeedbackRating: int
{
    case Positive = 1;
    case Neutral = 0;
    case Negative = -1;

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positive',
            self::Neutral => 'Neutral',
            self::Negative => 'Negative',
        };
    }

    public function isPositive(): bool
    {
        return $this === self::Positive;
    }

    public function isNegative(): bool
    {
        return $this === self::Negative;
    }
}
