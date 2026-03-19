<?php

namespace App\Infrastructure\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Laravel\Passport\HasApiTokens as PassportHasApiTokens;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Guard;
use Laravel\Sanctum\HasApiTokens as SanctumHasApiTokens;
use Laravel\Sanctum\Sanctum;

/**
 * Extends Sanctum's Guard to accept users that use Passport's HasApiTokens
 * instead of (or in addition to) Sanctum's HasApiTokens trait.
 *
 * Required because the User model uses Passport's HasApiTokens for MCP OAuth2
 * support, but the REST API still issues Sanctum tokens. The default Sanctum
 * guard rejects any model that doesn't carry Sanctum's trait, breaking all
 * /api/v1/ token authentication.
 *
 * Also overrides __invoke() to use ScopedTransientToken for session-based auth,
 * so the transient token satisfies Passport's typed ScopeAuthorizable property.
 */
class CompatibleSanctumGuard extends Guard
{
    /**
     * Retrieve the authenticated user for the incoming request.
     *
     * Mirrors Sanctum's Guard::__invoke() but replaces TransientToken with
     * ScopedTransientToken so it satisfies Passport's ?ScopeAuthorizable property.
     */
    public function __invoke(Request $request): mixed
    {
        foreach (Arr::wrap(config('sanctum.guard', 'web')) as $guard) {
            if ($user = $this->auth->guard($guard)->user()) {
                return $this->supportsTokens($user)
                    ? $user->withAccessToken(new ScopedTransientToken)
                    : $user;
            }
        }

        if ($token = $this->getTokenFromRequest($request)) {
            $model = Sanctum::$personalAccessTokenModel;

            $accessToken = $model::findToken($token);

            if (! $this->isValidAccessToken($accessToken) ||
                ! $this->supportsTokens($accessToken->tokenable)) {
                return null;
            }

            $tokenable = $accessToken->tokenable->withAccessToken($accessToken);

            event(new TokenAuthenticated($accessToken));

            if ($this->trackLastUsedAt) {
                $this->updateLastUsedAt($accessToken);
            }

            return $tokenable;
        }

        return null;
    }

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
