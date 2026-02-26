<?php

namespace App\Domain\Integration\Enums;

enum IntegrationStatus: string
{
    case Active = 'active';
    case Disconnected = 'disconnected';
    case Error = 'error';
    case PendingAuth = 'pending_auth';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disconnected => 'Disconnected',
            self::Error => 'Error',
            self::PendingAuth => 'Pending Auth',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'bg-green-100 text-green-800',
            self::Disconnected => 'bg-gray-100 text-gray-800',
            self::Error => 'bg-red-100 text-red-800',
            self::PendingAuth => 'bg-yellow-100 text-yellow-800',
        };
    }

    public function isHealthCheckable(): bool
    {
        return $this === self::Active;
    }
}
