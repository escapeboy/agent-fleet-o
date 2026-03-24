<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DisconnectIntegrationAction
{
    public function execute(Integration $integration): void
    {
        $this->revokeToken($integration);

        $integration->webhookRoutes()->delete();

        $integration->update(['status' => IntegrationStatus::Disconnected]);
        $integration->delete();
    }

    /**
     * Proactively revoke the OAuth2 access token at the provider before local deletion.
     * Fails silently — always proceeds with local deletion regardless of revocation outcome.
     */
    private function revokeToken(Integration $integration): void
    {
        $driver = (string) $integration->getAttribute('driver');

        // Only attempt revocation for OAuth2 drivers
        if (config("integrations.drivers.{$driver}.auth") !== 'oauth2') {
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
        $accessToken = (string) ($secretData['access_token'] ?? '');
        $refreshToken = (string) ($secretData['refresh_token'] ?? '');

        if (! $accessToken) {
            return;
        }

        try {
            match ($driver) {
                'google' => Http::timeout(10)->post(
                    'https://oauth2.googleapis.com/revoke',
                    ['token' => $accessToken],
                ),
                'slack' => Http::withToken($accessToken)->timeout(10)->post(
                    'https://slack.com/api/auth.revoke',
                ),
                'hubspot' => $refreshToken ? Http::timeout(10)->delete(
                    "https://api.hubapi.com/oauth/v1/refresh-tokens/{$refreshToken}",
                ) : null,
                'github' => Http::withBasicAuth(
                    (string) config('integrations.oauth.github.client_id'),
                    (string) config('integrations.oauth.github.client_secret'),
                )->timeout(10)->delete(
                    'https://api.github.com/applications/'.config('integrations.oauth.github.client_id').'/token',
                    ['access_token' => $accessToken],
                ),
                'salesforce' => ! empty($secretData['instance_url'])
                    ? Http::timeout(10)->post($secretData['instance_url'].'/services/oauth2/revoke', ['token' => $accessToken])
                    : null,
                // Providers without revocation endpoints — silently skip
                'linear', 'notion', 'airtable', 'asana', 'monday', 'clickup',
                'mailchimp', 'gitlab', 'bitbucket', 'confluence', 'typeform',
                'calendly', 'attio', 'pipedrive', 'intercom', 'zendesk',
                'freshdesk', 'sentry', 'pagerduty', 'jira', 'linkedin' => null,
                default => null,
            };
        } catch (\Throwable $e) {
            Log::info('DisconnectIntegrationAction: token revocation failed (non-critical)', [
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
