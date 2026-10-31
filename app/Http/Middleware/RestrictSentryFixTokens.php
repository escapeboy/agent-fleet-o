<?php

namespace App\Http\Middleware;

use App\Infrastructure\Auth\CompatibleSanctumGuard;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token carrying the `sentry-fixes` ability belongs to an external fix worker
 * that handles untrusted Sentry text. It may only reach the fix-task queue,
 * never the rest of the API (team, tokens, ...).
 */
class RestrictSentryFixTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() !== null ? $request->user('sanctum')?->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken
            && in_array('sentry-fixes', $token->abilities ?? [], true)
            && ! CompatibleSanctumGuard::isSentryFixTaskPath($request)) {
            abort(403, 'Tokens with the sentry-fixes ability can only access the sentry fix task endpoints.');
        }

        return $next($request);
    }
}
