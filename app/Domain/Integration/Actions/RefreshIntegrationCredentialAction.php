<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Credential\Models\Credential;
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
 */
class RefreshIntegrationCredentialAction
{
    /** Seconds before expiry to proactively refresh. */
    private const REFRESH_THRESHOLD_SECONDS = 600;

    public function execute(Integration $integration): void
    {
        $driver = (string) $integration->getAttribute('driver');
        $tokenUrl = config("integrations.oauth_urls.{$driver}.token");

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
                Log::error('RefreshIntegrationCredentialAction: refresh failed', [
                    'integration_id' => $integration->getKey(),
                    'driver' => $driver,
                    'status' => $response->status(),
                    'error' => $response->json('error') ?? 'unknown',
                ]);

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

            Log::info('RefreshIntegrationCredentialAction: token refreshed', [
                'integration_id' => $integration->getKey(),
                'driver' => $driver,
            ]);
        } finally {
            $lock->release();
        }
    }
}
