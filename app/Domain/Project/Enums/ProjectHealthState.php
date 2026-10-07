<?php

namespace App\Domain\Project\Enums;

enum ProjectHealthState: string
{
    case Off = 'off';
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Stopped = 'stopped';

    public function rank(): int
    {
        return match ($this) {
            self::Off, self::Ok => 0,
            self::Degraded => 1,
            self::Stopped => 2,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Ok => 'Healthy',
            self::Degraded => 'Degraded',
            self::Stopped => 'Stopped',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Off => 'bg-gray-200 text-gray-600',
            self::Ok => 'bg-green-100 text-green-800',
            self::Degraded => 'bg-yellow-100 text-yellow-800',
            self::Stopped => 'bg-red-100 text-red-800',
        };
    }
}
