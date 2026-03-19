<?php

namespace App\Infrastructure\Auth;

use Laravel\Passport\HasApiTokens as PassportHasApiTokens;
use Laravel\Sanctum\Guard;
use Laravel\Sanctum\HasApiTokens as SanctumHasApiTokens;

/**
 * Extends Sanctum's Guard to accept users that use Passport's HasApiTokens
 * instead of (or in addition to) Sanctum's HasApiTokens trait.
 *
 * Required because the User model uses Passport's HasApiTokens for MCP OAuth2
 * support, but the REST API still issues Sanctum tokens. The default Sanctum
 * guard rejects any model that doesn't carry Sanctum's trait, breaking all
 * /api/v1/ token authentication.
 */
class CompatibleSanctumGuard extends Guard
{
    protected function supportsTokens($tokenable = null): bool
    {
        if (! $tokenable) {
            return false;
        }

        $traits = class_uses_recursive(get_class($tokenable));

        return in_array(SanctumHasApiTokens::class, $traits)
            || in_array(PassportHasApiTokens::class, $traits);
    }
}
