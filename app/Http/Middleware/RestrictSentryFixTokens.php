<?php

namespace App\Http\Middleware;

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
            && ! $request->is('api/v1/sentry-fix-tasks', 'api/v1/sentry-fix-tasks/*')) {
            abort(403, 'Tokens with the sentry-fixes ability can only access the sentry fix task endpoints.');
        }

        return $next($request);
    }
}
