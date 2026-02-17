<?php

namespace App\Domain\Testing\Enums;

enum TestStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Passed, self::Failed, self::Skipped]);
    }
}
