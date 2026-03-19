<?php

namespace App\Infrastructure\Auth;

use Laravel\Passport\Contracts\ScopeAuthorizable;
use Laravel\Sanctum\TransientToken;

/**
 * TransientToken subclass that also implements Passport's ScopeAuthorizable.
 *
 * Used for session-based (web guard) authentication when the Sanctum guard wraps
 * a session-authenticated user with a transient token. Adding ScopeAuthorizable
 * allows this token to be stored in Passport's typed `?ScopeAuthorizable $accessToken`
 * property without a TypeError.
 */
class ScopedTransientToken extends TransientToken implements ScopeAuthorizable
{
    public function can(string $scope): bool
    {
        return true;
    }

    public function cant(string $scope): bool
    {
        return false;
    }
}
