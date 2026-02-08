<?php

namespace App\Domain\Skill\Enums;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function requiresApproval(): bool
    {
        return $this === self::High || $this === self::Critical;
    }
}
