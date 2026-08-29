<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Enums;

enum AgentChatVisibility: string
{
    case Private = 'private';
    case Team = 'team';
    case Marketplace = 'marketplace';
    case Public = 'public';

    /**
     * Coerce a raw attribute into the enum.
     *
     * Eloquent hands this back as the enum at runtime, but static analysis sees
     * the column's declared string type, so every `?->allowsPublicManifest()`
     * on it reads as a call on a string. Normalising once keeps the call sites
     * both correct and analysable instead of scattering baseline entries.
     */
    public static function normalize(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::tryFrom($value) : null;
    }

    public function allowsPublicManifest(): bool
    {
        return in_array($this, [self::Marketplace, self::Public], true);
    }

    public function requiresSanctum(): bool
    {
        return $this === self::Private || $this === self::Team;
    }
}
