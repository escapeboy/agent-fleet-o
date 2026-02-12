<?php

namespace App\Domain\Tool\Enums;

enum ToolStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'bg-green-100 text-green-800',
            self::Disabled => 'bg-gray-100 text-gray-800',
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
