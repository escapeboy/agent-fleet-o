<?php

namespace App\Domain\Chatbot\Enums;

enum ChatbotStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft => 'gray',
            self::Active => 'green',
            self::Inactive => 'yellow',
            self::Suspended => 'red',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
