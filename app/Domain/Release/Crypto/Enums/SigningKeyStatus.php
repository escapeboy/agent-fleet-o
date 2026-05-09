<?php

declare(strict_types=1);

namespace App\Domain\Release\Crypto\Enums;

enum SigningKeyStatus: string
{
    case Active = 'active';
    case Grace = 'grace';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Grace => 'Grace period',
            self::Revoked => 'Revoked',
        };
    }
}
