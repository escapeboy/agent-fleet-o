<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Credential\Models\Credential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Refresh an expired Reddit access token (token_v2) using the reddit_session cookie.
 *
 * Reddit issues a new token_v2 in the Set-Cookie header when you make any
 * authenticated request with a valid reddit_session cookie. The reddit_session
 * itself is long-lived (~6 months) so we only need to store it once.
 *
 * Credential secret_data fields:
 *   access_token      — current token_v2 value (used as Bearer token)
 *   reddit_session    — long-lived session cookie
 *   loid              — user identifier cookie
 *   token_expires_at  — ISO 8601 expiry of access_token
 */
class RefreshRedditTokenAction
{
    /** Seconds before expiry to proactively refresh. */
    private const REFRESH_THRESHOLD_SECONDS = 3600;

    private const REDDIT_API_URL = 'https://www.reddit.com/api/v1/me.json';

    public function execute(Credential $credential): string
    {
        /** @var array<string, mixed> $secretData */
        $secretData = $credential->getAttribute('secret_data') ?? [];

        $accessToken = (string) ($secretData['access_token'] ?? '');
        $expiresAt = $secretData['token_expires_at'] ?? null;

        // Return current token if still valid beyond threshold
        if ($accessToken && $expiresAt) {
            $expiryTime = strtotime((string) $expiresAt);
            if ($expiryTime && $expiryTime > (time() + self::REFRESH_THRESHOLD_SECONDS)) {
                return $accessToken;
            }
        }

        $redditSession = (string) ($secretData['reddit_session'] ?? '');
        if (! $redditSession) {
            throw new \RuntimeException('Reddit credential is missing reddit_session cookie. Re-extract it from the browser.');
        }

        $lockKey = "reddit_token_refresh:{$credential->getKey()}";
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            // Another process is refreshing — return existing token and let it finish
            return $accessToken;
        }

        try {
            $loid = (string) ($secretData['loid'] ?? '');
            $cookieHeader = "reddit_session={$redditSession}; token_v2={$accessToken}";
            if ($loid) {
                $cookieHeader .= "; loid={$loid}";
            }

            $response = Http::timeout(15)
                ->withHeaders([
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Mozilla/5.0 (compatible; FleetQ/1.0)',
                ])
                ->get(self::REDDIT_API_URL);

            if (! $response->successful()) {
                throw new \RuntimeException("Reddit token refresh failed: HTTP {$response->status()}. The reddit_session cookie may have expired.");
            }

            // Extract new token_v2 from Set-Cookie header
            $newToken = $this->extractTokenFromCookies($response->header('Set-Cookie') ?? '');

            if (! $newToken) {
                // Token is still valid if Reddit didn't issue a new one
                Log::info('RefreshRedditTokenAction: token still valid, no new token issued', [
                    'credential_id' => $credential->getKey(),
                ]);

                // Extend our local expiry estimate by 12 hours
                $credential->update([
                    'secret_data' => array_merge($secretData, [
                        'token_expires_at' => now()->addHours(12)->toIso8601String(),
                    ]),
                ]);

                return $accessToken;
            }

            $updated = array_merge($secretData, [
                'access_token' => $newToken,
                'token_expires_at' => now()->addHours(24)->toIso8601String(),
            ]);

            $credential->update([
                'secret_data' => $updated,
                'expires_at' => now()->addHours(24),
            ]);

            Log::info('RefreshRedditTokenAction: token refreshed successfully', [
                'credential_id' => $credential->getKey(),
            ]);

            return $newToken;
        } finally {
            $lock->release();
        }
    }

    private function extractTokenFromCookies(string $setCookieHeader): ?string
    {
        // Set-Cookie may come as a single string or multiple; split by ", " carefully
        $cookies = preg_split('/,(?=[^ ])/', $setCookieHeader) ?: [];

        foreach ($cookies as $cookie) {
            if (preg_match('/token_v2=([^;]+)/', $cookie, $matches)) {
                $value = trim($matches[1]);
                if ($value && $value !== 'deleted') {
                    return $value;
                }
            }
        }

        return null;
    }
}
