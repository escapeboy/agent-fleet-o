<?php

declare(strict_types=1);

namespace App\Domain\Skill\Enums;

enum FrameworkCategory: string
{
    case Validation = 'validation';
    case Sales = 'sales';
    case Growth = 'growth';
    case Finance = 'finance';
    case Engineering = 'engineering';
    case Operations = 'operations';

    public function label(): string
    {
        return match ($this) {
            self::Validation => 'Validation',
            self::Sales => 'Sales',
            self::Growth => 'Growth',
            self::Finance => 'Finance',
            self::Engineering => 'Engineering',
            self::Operations => 'Operations',
        };
    }
}
