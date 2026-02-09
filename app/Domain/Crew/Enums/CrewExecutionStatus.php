<?php

namespace App\Domain\Crew\Enums;

enum CrewExecutionStatus: string
{
    case Planning = 'planning';
    case Executing = 'executing';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Executing => 'Executing',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Terminated => 'Terminated',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planning => 'blue',
            self::Executing => 'blue',
            self::Paused => 'yellow',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Terminated => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Terminated]);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Planning, self::Executing]);
    }
}
