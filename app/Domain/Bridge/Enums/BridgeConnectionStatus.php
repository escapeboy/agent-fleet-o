<?php

namespace App\Domain\Bridge\Enums;

enum BridgeConnectionStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Reconnecting = 'reconnecting';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
            self::Reconnecting => 'Reconnecting',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Connected;
    }
}
