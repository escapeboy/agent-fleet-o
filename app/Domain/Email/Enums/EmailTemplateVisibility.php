<?php

namespace App\Domain\Email\Enums;

enum EmailTemplateVisibility: string
{
    case Private = 'private';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::Public => 'Public',
        };
    }
}
