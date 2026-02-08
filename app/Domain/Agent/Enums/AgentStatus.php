<?php

namespace App\Domain\Agent\Enums;

enum AgentStatus: string
{
    case Active = 'active';
    case Degraded = 'degraded';
    case Disabled = 'disabled';
    case Offline = 'offline';

    public function isAvailable(): bool
    {
        return $this === self::Active || $this === self::Degraded;
    }
}
