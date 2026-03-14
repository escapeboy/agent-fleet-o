<?php

namespace App\Domain\GitRepository\Enums;

enum GitRepositoryStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
            self::Error => 'Error',
        };
    }
}
