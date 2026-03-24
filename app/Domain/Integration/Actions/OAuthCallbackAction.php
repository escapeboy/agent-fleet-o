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
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => route('integrations.oauth.callback', $driver),
                ]);
        } else {
            $response = Http::timeout(15)
                ->asForm()
                ->post($tokenUrl, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => route('integrations.oauth.callback', $driver),
                    'client_id' => $oauthConfig['client_id'] ?? '',
                    'client_secret' => $oauthConfig['client_secret'] ?? '',
                ]);
        }

        if (! $response->successful()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'Token exchange failed';
            Log::error('OAuthCallbackAction: token exchange failed', [
                'driver' => $driver,
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new \RuntimeException("OAuth2 token exchange failed: {$error}");
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * Fetch the first accessible Jira Cloud site and return its id + url.
     * Returns null if the API call fails or returns no sites.
     *
     * @return array{id: string, url: string}|null
     */
    private function resolveJiraCloudId(string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get('https://api.atlassian.com/oauth/token/accessible-resources');

            if ($response->successful()) {
                /** @var array<int, array{id: string, url: string}> $sites */
                $sites = $response->json() ?? [];
                if (! empty($sites[0]['id'])) {
                    return ['id' => (string) $sites[0]['id'], 'url' => (string) ($sites[0]['url'] ?? '')];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('OAuthCallbackAction: could not resolve Jira cloudId', ['error' => $e->getMessage()]);
        }

        return null;
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

        // Linear: scope and token type are useful for diagnostics
        if ($driver === 'linear') {
            if (! empty($tokens['scope'])) {
                $credentials['scope'] = (string) $tokens['scope'];
            }
        }

        // LinkedIn: fetch userinfo to get the member's URN (sub field = LinkedIn member ID).
        // person_urn is required for all author-based API calls (posts, comments).
        // Primary: /v2/userinfo (requires openid scope from "Sign In with LinkedIn" product).
        // Fallback: decode the JWT access token to extract the sub claim (works with w_member_social only).
        if ($driver === 'linkedin') {
            try {
                $userinfo = Http::withToken($credentials['access_token'])
                    ->timeout(10)
                    ->get('https://api.linkedin.com/v2/userinfo')
                    ->json();

                if (! empty($userinfo['sub'])) {
                    $credentials['person_urn'] = 'urn:li:person:'.$userinfo['sub'];
                }
                if (! empty($userinfo['name'])) {
                    $credentials['name'] = (string) $userinfo['name'];
                }
                if (! empty($userinfo['email'])) {
                    $credentials['email'] = (string) $userinfo['email'];
                }
            } catch (\Throwable $e) {
                Log::warning('OAuthCallbackAction: could not fetch LinkedIn userinfo', ['error' => $e->getMessage()]);
            }

            // Fallback: if person_urn is still missing, attempt to decode the JWT access token.
            // LinkedIn access tokens are JWTs whose payload contains the member ID as the "sub" claim.
            if (empty($credentials['person_urn']) && ! empty($credentials['access_token'])) {
                try {
                    $parts = explode('.', $credentials['access_token']);
                    if (count($parts) === 3) {
                        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                        if (! empty($payload['sub'])) {
                            $credentials['person_urn'] = 'urn:li:person:'.$payload['sub'];
                            Log::info('OAuthCallbackAction: extracted LinkedIn person URN from JWT', ['person_urn' => $credentials['person_urn']]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('OAuthCallbackAction: could not decode LinkedIn JWT', ['error' => $e->getMessage()]);
                }
            }
        }

        // Jira: resolve the Atlassian cloudId from the accessible-resources endpoint.
        // cloudId is required for all Jira Cloud API calls and webhook registration.
        if ($driver === 'jira') {
            $cloudInfo = $this->resolveJiraCloudId((string) ($credentials['access_token']));
            if ($cloudInfo) {
                $credentials['cloud_id'] = $cloudInfo['id'];
                $credentials['cloud_url'] = rtrim($cloudInfo['url'], '/');
            }
        }

        return $credentials;
    }
}
