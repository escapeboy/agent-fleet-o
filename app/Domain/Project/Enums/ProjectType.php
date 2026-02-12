<?php

namespace App\Domain\Project\Enums;

enum ProjectType: string
{
    case OneShot = 'one_shot';
    case Continuous = 'continuous';

    public function label(): string
    {
        return match ($this) {
            self::OneShot => 'One-Shot',
            self::Continuous => 'Continuous',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OneShot => '⚡',
            self::Continuous => '🔄',
        };
    }
}
