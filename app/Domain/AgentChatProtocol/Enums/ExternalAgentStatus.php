<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Enums;

enum ExternalAgentStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Unreachable = 'unreachable';
    case Disabled = 'disabled';

    public function isCallable(): bool
    {
        return $this === self::Active;
    }
}
