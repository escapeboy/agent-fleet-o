<?php

namespace App\Domain\Credential\Enums;

enum CredentialStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case PendingReview = 'pending_review';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
            self::PendingReview => 'Pending Review',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'bg-green-100 text-green-800',
            self::Disabled => 'bg-gray-100 text-gray-800',
            self::PendingReview => 'bg-orange-100 text-orange-800',
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
