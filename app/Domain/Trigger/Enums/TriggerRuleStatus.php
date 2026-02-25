<?php

namespace App\Domain\Trigger\Enums;

enum TriggerRuleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
