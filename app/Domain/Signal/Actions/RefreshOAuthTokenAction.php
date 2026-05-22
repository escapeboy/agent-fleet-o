<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Shared\Models\TeamProviderCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Refresh an OAuth2 access token stored in TeamProviderCredential.
 *
 * Supports: gmail, outlook
 *
 * Credentials JSONB structure (encrypted in TeamProviderCredential):
 *   access_token     — current short-lived token
 *   refresh_token    — long-lived token for renewal
 *   token_expires_at — ISO 8601 expiry timestamp
 *   client_id        — OAuth2 app client ID
 *   client_secret    — OAuth2 app client secret
 *   provider         — 'gmail' | 'outlook'
 */
class RefreshOAuthTokenAction
{
    private const GMAIL_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const OUTLOOK_TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    /** Seconds before expiry to proactively refresh. */
    private const REFRESH_THRESHOLD_SECONDS = 60;

    /**
     * Return a valid access token, refreshing if it is expired or about to expire.
     *
     * @throws \RuntimeException when the credential is not found or token refresh fails
     */
    public function execute(string $credentialId): string
    {
        /** @var TeamProviderCredential|null $credential */
        $credential = TeamProviderCredential::find($credentialId);

        if (! $credential) {
            throw new \RuntimeException("OAuth2 credential {$credentialId} not found.");
        }

        $creds = $credential->credentials ?? [];
        $accessToken = $creds['access_token'] ?? null;
        $expiresAt = $creds['token_expires_at'] ?? null;

        // Return current token if still valid
        if ($accessToken && $expiresAt) {
            $expiryTime = strtotime($expiresAt);
            if ($expiryTime && $expiryTime > (time() + self::REFRESH_THRESHOLD_SECONDS)) {
                return $accessToken;
            }
        }

        // Refresh the token
        $refreshToken = $creds['refresh_token'] ?? null;

        if (! $refreshToken) {
            throw new \RuntimeException("OAuth2 credential {$credentialId} has no refresh_token. Re-authorize the connection.");
        }

        $provider = $creds['provider'] ?? 'gmail';
        $newTokens = $this->refreshToken($provider, $creds);

        // Persist updated tokens
        $updatedCreds = array_merge($creds, [
            'access_token' => $newTokens['access_token'],
            'token_expires_at' => date('c', time() + ((int) ($newTokens['expires_in'] ?? 3600))),
        ]);

        if (! empty($newTokens['refresh_token'])) {
            $updatedCreds['refresh_token'] = $newTokens['refresh_token'];
        }

        $credential->update(['credentials' => $updatedCreds]);

        return $newTokens['access_token'];
    }

    /**
     * @return array{access_token: string, expires_in: int, refresh_token?: string}
     *
     * @throws \RuntimeException on failed refresh
     */
    private function refreshToken(string $provider, array $creds): array
    {
        $tokenUrl = match ($provider) {
            'outlook' => self::OUTLOOK_TOKEN_URL,
            default => self::GMAIL_TOKEN_URL,
        };

        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $creds['refresh_token'],
            'client_id' => $creds['client_id'] ?? '',
            'client_secret' => $creds['client_secret'] ?? '',
        ];

        if ($provider === 'outlook') {
            $params['scope'] = 'https://outlook.office365.com/.default offline_access';
        }

        $response = Http::timeout(15)->asForm()->post($tokenUrl, $params);

        if (! $response->successful()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'Unknown error';
            Log::error('RefreshOAuthTokenAction: Token refresh failed', [
                'provider' => $provider,
                'status' => $response->status(),
                'error' => $error,
            ]);

            throw new \RuntimeException("OAuth2 token refresh failed for provider '{$provider}': {$error}");
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new \RuntimeException('OAuth2 token refresh response missing access_token.');
        }

        return $data;
    }
}
