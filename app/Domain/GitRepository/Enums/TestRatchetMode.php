<?php

namespace App\Domain\GitRepository\Enums;

enum TestRatchetMode: string
{
    case Off = 'off';
    case Soft = 'soft';
    case Hard = 'hard';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Soft => 'Soft (nudge only)',
            self::Hard => 'Hard (block via approval)',
        };
    }
}
