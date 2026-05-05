<?php

namespace App\Domain\AgentSession\Enums;

enum AgentSessionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Sleeping = 'sleeping';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Sleeping => 'Sleeping',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }
}
