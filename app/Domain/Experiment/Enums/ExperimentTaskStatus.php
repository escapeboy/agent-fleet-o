<?php

namespace App\Domain\Experiment\Enums;

enum ExperimentTaskStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Queued => 'yellow',
            self::Running => 'blue',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Skipped => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => '○',
            self::Queued => '◷',
            self::Running => '⟳',
            self::Completed => '✓',
            self::Failed => '✗',
            self::Skipped => '⊘',
        };
    }
}
