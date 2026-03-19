<?php

namespace App\Infrastructure\Auth;

use Laravel\Passport\Contracts\ScopeAuthorizable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * PersonalAccessToken subclass that also implements Passport's ScopeAuthorizable.
 *
 * Allows Sanctum tokens to be stored in Passport's typed `?ScopeAuthorizable $accessToken`
 * property on the User model, enabling Sanctum and Passport to coexist on the same User.
 *
 * ScopeAuthorizable only requires can() and cant(), which PersonalAccessToken already
 * satisfies via HasAbilities — we just need to add the interface and type-annotate.
 */
class ScopedPersonalAccessToken extends PersonalAccessToken implements ScopeAuthorizable
{
    protected $table = 'personal_access_tokens';

    public function can($scope): bool
    {
        return parent::can($scope);
    }

    public function cant($scope): bool
    {
        return parent::cant($scope);
    }
}
