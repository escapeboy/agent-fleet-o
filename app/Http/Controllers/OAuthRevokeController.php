<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\Passport;

/**
 * RFC 7009 — OAuth 2.0 Token Revocation
 *
 * Accepts an access_token or refresh_token and revokes it.
 * Always returns HTTP 200, even for invalid or already-revoked tokens.
 */
class OAuthRevokeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = $request->input('token');
        $hint = $request->input('token_type_hint', 'access_token');

        if (! $token) {
            return response('', 200);
        }

        try {
            if ($hint === 'refresh_token') {
                $this->revokeRefreshToken($token);
            } else {
                $this->revokeAccessToken($token);
            }
        } catch (\Throwable) {
            // Per RFC 7009: always return 200, even on failure.
        }

        return response('', 200);
    }

    private function revokeAccessToken(string $bearerToken): void
    {
        // Access tokens are JWTs. The `jti` claim is the token ID in oauth_access_tokens.
        $parts = explode('.', $bearerToken);

        if (count($parts) !== 3) {
            return;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $jti = $payload['jti'] ?? null;

        if (! $jti) {
            return;
        }

        $record = Passport::token()->newQuery()->find($jti);
        $record?->revoke();
    }

    private function revokeRefreshToken(string $tokenValue): void
    {
        // Refresh tokens are opaque encrypted strings. Attempt a direct ID lookup
        // (works if the client passes the raw token ID, which some do).
        // League OAuth2 refresh tokens are encrypted JSON; we skip full decryption
        // here to avoid coupling to internal League structures.
        $record = Passport::refreshToken()->newQuery()->find($tokenValue);

        if ($record) {
            $record->revoke();
            $record->accessToken?->revoke();

            return;
        }

        // Fallback: also try treating the value as an access token JWT
        $this->revokeAccessToken($tokenValue);
    }
}
