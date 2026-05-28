<?php

namespace App\Domain\Tool\Enums;

enum RegistryTrustLevel: string
{
    /** Vetted and signed by the FleetQ platform team. Safe to expose to all tenants. */
    case PlatformTrusted = 'platform_trusted';

    /** Verified by a platform admin (the local super-admin) but not platform-signed. */
    case Verified = 'verified';

    /** Community-contributed. Use at your own risk. */
    case Community = 'community';

    public function label(): string
    {
        return match ($this) {
            self::PlatformTrusted => 'Platform-trusted',
            self::Verified => 'Verified',
            self::Community => 'Community',
        };
    }
}
