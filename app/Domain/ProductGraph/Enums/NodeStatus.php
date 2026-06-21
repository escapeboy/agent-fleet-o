<?php

namespace App\Domain\ProductGraph\Enums;

enum NodeStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Implemented = 'implemented';
    case Deprecated = 'deprecated';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Implemented => 'Implemented',
            self::Deprecated => 'Deprecated',
        };
    }

    /** Tailwind badge classes for UI rendering. */
    public function color(): string
    {
        return match ($this) {
            self::Planned => 'bg-gray-100 text-gray-700',
            self::InProgress => 'bg-amber-100 text-amber-800',
            self::Implemented => 'bg-emerald-100 text-emerald-800',
            self::Deprecated => 'bg-red-100 text-red-700',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::values());
    }
}
