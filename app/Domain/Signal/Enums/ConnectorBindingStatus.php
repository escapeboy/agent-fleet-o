<?php

namespace App\Domain\Signal\Enums;

enum ConnectorBindingStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Blocked => 'Blocked',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function isBlocked(): bool
    {
        return $this === self::Blocked;
    }
}
