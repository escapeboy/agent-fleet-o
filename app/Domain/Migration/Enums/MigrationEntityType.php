<?php

namespace App\Domain\Migration\Enums;

enum MigrationEntityType: string
{
    case Contact = 'contact';

    public function label(): string
    {
        return match ($this) {
            self::Contact => 'Contact',
        };
    }
}
