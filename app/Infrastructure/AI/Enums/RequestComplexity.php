<?php

namespace App\Infrastructure\AI\Enums;

enum RequestComplexity: string
{
    case Light = 'light';
    case Standard = 'standard';
    case Heavy = 'heavy';

    /**
     * Map to the experiment model tier name used in config/experiments.php.
     */
    public function toModelTier(): string
    {
        return match ($this) {
            self::Light => 'cheap',
            self::Standard => 'standard',
            self::Heavy => 'expensive',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Light => 1,
            self::Standard => 2,
            self::Heavy => 3,
        };
    }

    /**
     * Get the next tier up for escalation.
     */
    public function escalate(): ?self
    {
        return match ($this) {
            self::Light => self::Standard,
            self::Standard => self::Heavy,
            self::Heavy => null,
        };
    }
}
