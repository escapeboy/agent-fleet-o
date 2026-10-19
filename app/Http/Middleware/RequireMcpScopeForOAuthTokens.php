<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * An OAuth access token may only open the MCP endpoints with the `mcp:use` scope.
 * The `passport` guard alone accepts any Passport token, so a token issued without
 * `mcp:use` (no scope, or any scope added later) would give full MCP access.
 * Sanctum tokens and sessions are not affected.
 */
class RequireMcpScopeForOAuthTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->token();

        if ($token instanceof AccessToken && $token->cant('mcp:use')) {
            return response()->json([
                'error' => 'insufficient_scope',
                'error_description' => 'This token does not carry the mcp:use scope.',
            ], 403);
        }

        return $next($request);
    }
}
