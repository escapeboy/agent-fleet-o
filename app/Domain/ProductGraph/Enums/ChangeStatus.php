<?php

namespace App\Domain\ProductGraph\Enums;

enum ChangeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Applied => 'Applied',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-100 text-amber-800',
            self::Approved => 'bg-blue-100 text-blue-800',
            self::Rejected => 'bg-red-100 text-red-700',
            self::Applied => 'bg-emerald-100 text-emerald-800',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
