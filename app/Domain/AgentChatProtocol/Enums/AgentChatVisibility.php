<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Enums;

enum AgentChatVisibility: string
{
    case Private = 'private';
    case Team = 'team';
    case Marketplace = 'marketplace';
    case Public = 'public';

    public function allowsPublicManifest(): bool
    {
        return in_array($this, [self::Marketplace, self::Public], true);
    }

    public function requiresSanctum(): bool
    {
        return $this === self::Private || $this === self::Team;
    }
}
