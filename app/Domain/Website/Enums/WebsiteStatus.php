<?php

namespace App\Domain\Website\Enums;

enum WebsiteStatus: string
{
    case Generating = 'generating';
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Generating => 'Generating',
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Generating => 'blue',
            self::Draft => 'gray',
            self::Published => 'green',
            self::Archived => 'yellow',
        };
    }
}
