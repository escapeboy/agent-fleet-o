<?php

namespace App\Domain\Migration\Enums;

enum MigrationStatus: string
{
    case Pending = 'pending';
    case Analysing = 'analysing';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            default => false,
        };
    }

    public function canExecute(): bool
    {
        return in_array($this, [self::AwaitingConfirmation, self::Pending], true);
    }
}
