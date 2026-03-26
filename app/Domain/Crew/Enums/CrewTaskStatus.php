<?php

namespace App\Domain\Crew\Enums;

enum CrewTaskStatus: string
{
    case Pending = 'pending';
    case Blocked = 'blocked';
    case Assigned = 'assigned';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case NeedsRevision = 'needs_revision';
    case Validated = 'validated';
    case QaFailed = 'qa_failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Blocked => 'Blocked',
            self::Assigned => 'Assigned',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::NeedsRevision => 'Needs Revision',
            self::Validated => 'Validated',
            self::QaFailed => 'QA Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Blocked => 'orange',
            self::Assigned => 'sky',
            self::Running => 'blue',
            self::Completed => 'teal',
            self::Failed => 'red',
            self::NeedsRevision => 'amber',
            self::Validated => 'green',
            self::QaFailed => 'red',
            self::Skipped => 'gray',
        };
    }

    /**
     * Terminal states are those that require no further processing.
     * Blocked is NOT terminal — it is waiting for dependencies to complete.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Validated, self::QaFailed, self::Skipped]);
    }

    /**
     * Active states are those where a task is currently being worked on.
     * Blocked is NOT active — it is not being processed.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Assigned, self::Running, self::NeedsRevision]);
    }

    /**
     * Whether this task is waiting for its dependencies to complete.
     */
    public function isBlocked(): bool
    {
        return $this === self::Blocked;
    }
}
