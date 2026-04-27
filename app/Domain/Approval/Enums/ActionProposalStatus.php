<?php

namespace App\Domain\Approval\Enums;

enum ActionProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Executed = 'executed';
    case ExecutionFailed = 'execution_failed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Executed => 'Executed',
            self::ExecutionFailed => 'Execution failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'sky',
            self::Rejected => 'red',
            self::Expired => 'gray',
            self::Executed => 'emerald',
            self::ExecutionFailed => 'rose',
        };
    }
}
