<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Refresh an expiring OAuth2 access token for an integration.
 *
 * Uses a Redis lock (SETNX) to prevent parallel refresh races.
 * Silently returns if:
 *   - The driver has no OAuth2 token URL
 *   - No refresh_token is stored
 *   - The current token is still valid beyond the 10-minute threshold
 *
 * On permanent failure (invalid_grant / 400), sets integration status
 * to RequiresReauth so users are prompted to re-authorize.
 */
class RefreshIntegrationCredentialAction
{
    /** Seconds before expiry to proactively refresh. */
    private const REFRESH_THRESHOLD_SECONDS = 600;

    /** OAuth2 error codes that indicate a permanently invalid token. */
    private const PERMANENT_FAILURE_ERRORS = ['invalid_grant', 'invalid_token', 'token_expired'];

    public function execute(Integration $integration): void
    {
        $driver = (string) $integration->getAttribute('driver');
        $tokenUrl = $this->resolveTokenUrl($driver, $integration);

        if (! $tokenUrl) {
            return;
        }

        $credentialId = $integration->getAttribute('credential_id');
        if (! $credentialId) {
            return;
        }

        /** @var Credential|null $credential */
        $credential = Credential::find($credentialId);
        if (! $credential) {
            return;
        }

        /** @var array<string, mixed> $secretData */
        $secretData = $credential->getAttribute('secret_data') ?? [];

        $expiresAt = $secretData['token_expires_at'] ?? null;

        if ($expiresAt) {
            $expiryTime = strtotime((string) $expiresAt);
            if ($expiryTime && $expiryTime > (time() + self::REFRESH_THRESHOLD_SECONDS)) {
                return;
            }
        }

        $refreshToken = $secretData['refresh_token'] ?? null;
        if (! $refreshToken) {
            return;
        }

        $lockKey = "integration_token_refresh:{$integration->getKey()}";
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            return;
        }

        try {
            $oauthConfig = config("integrations.oauth.{$driver}", []);

            $response = Http::timeout(15)->asForm()->post((string) $tokenUrl, [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $refreshToken,
                'client_id' => $oauthConfig['client_id'] ?? '',
                'client_secret' => $oauthConfig['client_secret'] ?? '',
            ]);

            if (! $response->successful()) {
                $errorCode = $response->json('error') ?? 'unknown';

                Log::error('RefreshIntegrationCredentialAction: refresh failed', [
                    'integration_id' => $integration->getKey(),
                    'driver' => $driver,
                    'status' => $response->status(),
                    'error' => $errorCode,
                ]);

                // Permanent failure: the refresh token is invalid or revoked
                if ($response->status() === 400 || $response->status() === 401) {
                    if (in_array($errorCode, self::PERMANENT_FAILURE_ERRORS, true) || $response->status() === 401) {
                        $integration->update(['status' => IntegrationStatus::RequiresReauth]);

                        Log::warning('RefreshIntegrationCredentialAction: token permanently invalid, requires re-auth', [
                            'integration_id' => $integration->getKey(),
                            'driver' => $driver,
                        ]);
                    }
                }

                return;
            }

            /** @var array<string, mixed> $data */
            $data = $response->json();

            $expiresIn = (int) ($data['expires_in'] ?? 3600);

            $updated = array_merge($secretData, [
                'access_token' => (string) ($data['access_token'] ?? ''),
                'token_expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
            ]);

            if (! empty($data['refresh_token'])) {
                $updated['refresh_token'] = (string) $data['refresh_token'];
            }

            $credential->update([
                'secret_data' => $updated,
                'expires_at' => now()->addSeconds($expiresIn),
            ]);

            // Restore active status if it was previously set to requires_reauth
            if ($integration->getAttribute('status') === IntegrationStatus::RequiresReauth) {
                $integration->update(['status' => IntegrationStatus::Active]);
            }

            Log::info('RefreshIntegrationCredentialAction: token refreshed', [
                'integration_id' => $integration->getKey(),
                'driver' => $driver,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Resolve the token URL for the driver, handling subdomain-based providers.
     */
    private function resolveTokenUrl(string $driver, Integration $integration): ?string
    {
        $configured = config("integrations.oauth_urls.{$driver}.token");

        if ($configured) {
            return $configured;
        }

        // Subdomain-based providers store subdomain in credential secret_data
        $credentialId = $integration->getAttribute('credential_id');
        if (! $credentialId) {
            return null;
        }

        /** @var Credential|null $credential */
        $credential = Credential::find($credentialId);
        $subdomain = $credential ? ($credential->getAttribute('secret_data')['subdomain'] ?? null) : null;

        if ($subdomain) {
            // Fix 2: validate subdomain before interpolating into URLs (defense-in-depth)
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]$/', $subdomain)) {
                Log::warning('RefreshIntegrationCredentialAction: invalid subdomain in credentials, skipping refresh', [
                    'integration_id' => $integration->getKey(),
                    'driver' => $driver,
                ]);

                return null;
            }

            return match ($driver) {
                'zendesk' => "https://{$subdomain}.zendesk.com/oauth/tokens",
                'freshdesk' => "https://{$subdomain}.freshdesk.com/oauth/token",
                default => null,
            };
        }

        return null;
    }
}
