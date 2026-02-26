<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Complete an OAuth2 authorization flow.
 *
 * Validates the state token, exchanges the authorization code for
 * access/refresh tokens, then delegates to ConnectIntegrationAction
 * to persist the Credential and Integration records.
 */
class OAuthCallbackAction
{
    public function __construct(
        private readonly ConnectIntegrationAction $connectAction,
    ) {}

    public function execute(string $driver, string $code, string $state): Integration
    {
        $cacheKey = "integration_oauth_state:{$state}";

        /** @var array{team_id: string, driver: string, name: string}|null $stateData */
        $stateData = Cache::store('redis')->get($cacheKey);

        if (! $stateData || $stateData['driver'] !== $driver) {
            throw new \InvalidArgumentException('Invalid or expired OAuth2 state. Please start the connection again.');
        }

        Cache::store('redis')->forget($cacheKey);

        $tokens = $this->exchangeCode($driver, $code);

        $credentials = $this->buildCredentials($driver, $tokens);

        return $this->connectAction->execute(
            teamId: $stateData['team_id'],
            driver: $driver,
            name: $stateData['name'],
            credentials: $credentials,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(string $driver, string $code): array
    {
        $oauthConfig = config("integrations.oauth.{$driver}");
        $tokenUrl = config("integrations.oauth_urls.{$driver}.token");

        if ($driver === 'notion') {
            // Notion uses Basic auth + JSON body
            $response = Http::timeout(15)
                ->withBasicAuth(
                    (string) ($oauthConfig['client_id'] ?? ''),
                    (string) ($oauthConfig['client_secret'] ?? ''),
                )
                ->asJson()
                ->post($tokenUrl, [
                    'grant_type'   => 'authorization_code',
                    'code'         => $code,
                    'redirect_uri' => route('integrations.oauth.callback', $driver),
                ]);
        } else {
            $response = Http::timeout(15)
                ->asForm()
                ->post($tokenUrl, [
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => route('integrations.oauth.callback', $driver),
                    'client_id'     => $oauthConfig['client_id'] ?? '',
                    'client_secret' => $oauthConfig['client_secret'] ?? '',
                ]);
        }

        if (! $response->successful()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'Token exchange failed';
            Log::error('OAuthCallbackAction: token exchange failed', [
                'driver' => $driver,
                'status' => $response->status(),
                'error'  => $error,
            ]);
            throw new \RuntimeException("OAuth2 token exchange failed: {$error}");
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * Normalize token response into a credential secret_data array.
     *
     * @param  array<string, mixed>  $tokens
     * @return array<string, mixed>
     */
    private function buildCredentials(string $driver, array $tokens): array
    {
        $credentials = [
            'access_token' => (string) ($tokens['access_token'] ?? ''),
        ];

        if (! empty($tokens['refresh_token'])) {
            $credentials['refresh_token'] = (string) $tokens['refresh_token'];
        }

        if (! empty($tokens['expires_in'])) {
            $credentials['token_expires_at'] = now()->addSeconds((int) $tokens['expires_in'])->toIso8601String();
        }

        // Slack: bot token is top-level; preserve workspace metadata
        if ($driver === 'slack') {
            if (! empty($tokens['bot_user_id'])) {
                $credentials['bot_user_id'] = (string) $tokens['bot_user_id'];
            }
            if (! empty($tokens['team']['id'])) {
                $credentials['workspace_id'] = (string) $tokens['team']['id'];
            }
            if (! empty($tokens['team']['name'])) {
                $credentials['workspace_name'] = (string) $tokens['team']['name'];
            }
        }

        // Notion: workspace metadata
        if ($driver === 'notion') {
            if (! empty($tokens['workspace_id'])) {
                $credentials['workspace_id'] = (string) $tokens['workspace_id'];
            }
            if (! empty($tokens['workspace_name'])) {
                $credentials['workspace_name'] = (string) $tokens['workspace_name'];
            }
            if (! empty($tokens['bot_id'])) {
                $credentials['bot_id'] = (string) $tokens['bot_id'];
            }
        }

        return $credentials;
    }
}
