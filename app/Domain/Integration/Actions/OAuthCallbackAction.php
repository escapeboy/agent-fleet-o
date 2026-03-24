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

    public function execute(string $driver, string $code, string $state, ?string $authenticatedTeamId = null): Integration
    {
        $cacheKey = "integration_oauth_state:{$state}";

        /** @var array{team_id: string, driver: string, name: string, subdomain: string|null, code_verifier: string|null}|null $stateData */
        $stateData = Cache::store('redis')->get($cacheKey);

        if (! $stateData || $stateData['driver'] !== $driver) {
            throw new \InvalidArgumentException('Invalid or expired OAuth2 state. Please start the connection again.');
        }

        // Fix 1: bind state token to the authenticated user's team to prevent cross-team token hijacking
        if ($authenticatedTeamId !== null && $stateData['team_id'] !== $authenticatedTeamId) {
            Cache::store('redis')->forget($cacheKey);
            throw new \InvalidArgumentException('OAuth2 state token does not match your current team. Please start the connection again.');
        }

        Cache::store('redis')->forget($cacheKey);

        $tokens = $this->exchangeCode($driver, $code, $stateData);

        $credentials = $this->buildCredentials($driver, $tokens, $stateData);

        return $this->connectAction->execute(
            teamId: $stateData['team_id'],
            driver: $driver,
            name: $stateData['name'],
            credentials: $credentials,
        );
    }

    /**
     * @param  array<string, mixed>  $stateData
     * @return array<string, mixed>
     */
    private function exchangeCode(string $driver, string $code, array $stateData): array
    {
        $oauthConfig = config("integrations.oauth.{$driver}");
        $tokenUrl = $this->resolveTokenUrl($driver, $stateData['subdomain'] ?? null);

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
        } elseif ($driver === 'clickup') {
            // ClickUp uses query parameters for token exchange, not form body
            $response = Http::timeout(15)
                ->post($tokenUrl.'?'.http_build_query([
                    'client_id' => $oauthConfig['client_id'] ?? '',
                    'client_secret' => $oauthConfig['client_secret'] ?? '',
                    'code' => $code,
                ]));
        } elseif ($driver === 'github') {
            // GitHub returns JSON but expects Accept: application/json
            $response = Http::timeout(15)
                ->withHeaders(['Accept' => 'application/json'])
                ->asForm()
                ->post($tokenUrl, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => route('integrations.oauth.callback', $driver),
                    'client_id' => $oauthConfig['client_id'] ?? '',
                    'client_secret' => $oauthConfig['client_secret'] ?? '',
                ]);
        } else {
            $params = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => route('integrations.oauth.callback', $driver),
                'client_id' => $oauthConfig['client_id'] ?? '',
                'client_secret' => $oauthConfig['client_secret'] ?? '',
            ];

            // PKCE: include code_verifier instead of client_secret verification
            if (! empty($stateData['code_verifier'])) {
                $params['code_verifier'] = $stateData['code_verifier'];
            }

            $response = Http::timeout(15)->asForm()->post($tokenUrl, $params);
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
     * Resolve the token URL, handling subdomain-based providers.
     */
    private function resolveTokenUrl(string $driver, ?string $subdomain): string
    {
        $configured = config("integrations.oauth_urls.{$driver}.token");

        if ($configured) {
            return $configured;
        }

        if ($subdomain) {
            // Fix 2: validate subdomain before interpolating into URLs
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]$/', $subdomain)) {
                throw new \InvalidArgumentException('Invalid subdomain in OAuth state: must contain only letters, digits, and hyphens.');
            }

            return match ($driver) {
                'zendesk' => "https://{$subdomain}.zendesk.com/oauth/tokens",
                'freshdesk' => "https://{$subdomain}.freshdesk.com/oauth/token",
                default => throw new \InvalidArgumentException("No token URL for driver: {$driver}"),
            };
        }

        throw new \InvalidArgumentException("No token URL configured for driver: {$driver}");
    }

    /**
     * Fetch the first accessible Atlassian Cloud site and return its id + url.
     *
     * @return array{id: string, url: string}|null
     */
    private function resolveAtlassianCloudId(string $accessToken): ?array
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
            Log::warning('OAuthCallbackAction: could not resolve Atlassian cloudId', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Normalize token response into a credential secret_data array.
     *
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $stateData
     * @return array<string, mixed>
     */
    private function buildCredentials(string $driver, array $tokens, array $stateData): array
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
        // LinkedIn access tokens expire in ~60 days; expires_in is unreliable, so default to 55 days.
        if ($driver === 'linkedin') {
            // Set a safe 55-day expiry regardless of what expires_in reports
            $credentials['token_expires_at'] = now()->addDays(55)->toIso8601String();

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
        }

        // Jira / Confluence: resolve the Atlassian cloudId from the accessible-resources endpoint.
        if ($driver === 'jira' || $driver === 'confluence') {
            $cloudInfo = $this->resolveAtlassianCloudId((string) ($credentials['access_token']));
            if ($cloudInfo) {
                $credentials['cloud_id'] = $cloudInfo['id'];
                $credentials['cloud_url'] = rtrim($cloudInfo['url'], '/');
            }
        }

        // Salesforce: instance_url is required for all API calls (org-specific)
        // Fix 6: validate host to prevent SSRF via compromised token endpoint
        if ($driver === 'salesforce' && ! empty($tokens['instance_url'])) {
            $instanceUrl = rtrim((string) $tokens['instance_url'], '/');
            $host = (string) parse_url($instanceUrl, PHP_URL_HOST);
            if ($host && (str_ends_with($host, '.salesforce.com') || str_ends_with($host, '.force.com'))) {
                $credentials['instance_url'] = $instanceUrl;
            } else {
                Log::warning('OAuthCallbackAction: rejected invalid Salesforce instance_url', ['host' => $host]);
            }
        }

        // GitHub: store the authenticated user's login for display purposes
        if ($driver === 'github') {
            try {
                $user = Http::withToken($credentials['access_token'])
                    ->timeout(10)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get('https://api.github.com/user')
                    ->json();

                if (! empty($user['login'])) {
                    $credentials['github_login'] = (string) $user['login'];
                }
            } catch (\Throwable $e) {
                Log::warning('OAuthCallbackAction: could not fetch GitHub user', ['error' => $e->getMessage()]);
            }
        }

        // Mailchimp: fetch datacenter prefix from metadata endpoint (required for all API calls)
        if ($driver === 'mailchimp') {
            try {
                $meta = Http::withToken($credentials['access_token'])
                    ->timeout(10)
                    ->get('https://login.mailchimp.com/oauth2/metadata')
                    ->json();

                if (! empty($meta['dc'])) {
                    $credentials['dc'] = (string) $meta['dc'];
                }
                if (! empty($meta['accountname'])) {
                    $credentials['account_name'] = (string) $meta['accountname'];
                }
            } catch (\Throwable $e) {
                Log::warning('OAuthCallbackAction: could not fetch Mailchimp metadata', ['error' => $e->getMessage()]);
            }
        }

        // Bitbucket: store first accessible workspace slug
        if ($driver === 'bitbucket') {
            try {
                $workspaces = Http::withToken($credentials['access_token'])
                    ->timeout(10)
                    ->get('https://api.bitbucket.org/2.0/workspaces')
                    ->json();

                $slug = $workspaces['values'][0]['slug'] ?? null;
                if ($slug) {
                    $credentials['workspace_slug'] = (string) $slug;
                }
            } catch (\Throwable $e) {
                Log::warning('OAuthCallbackAction: could not fetch Bitbucket workspaces', ['error' => $e->getMessage()]);
            }
        }

        // ClickUp: store team_id (workspace) for API calls
        if ($driver === 'clickup') {
            try {
                $teams = Http::withHeaders(['Authorization' => $credentials['access_token']])
                    ->timeout(10)
                    ->get('https://api.clickup.com/api/v2/team')
                    ->json();

                $teamId = $teams['teams'][0]['id'] ?? null;
                if ($teamId) {
                    $credentials['team_id'] = (string) $teamId;
                }
            } catch (\Throwable $e) {
                Log::warning('OAuthCallbackAction: could not fetch ClickUp team', ['error' => $e->getMessage()]);
            }
        }

        // Zendesk / Freshdesk: preserve the subdomain for API calls
        if (in_array($driver, ['zendesk', 'freshdesk'], true) && ! empty($stateData['subdomain'])) {
            $credentials['subdomain'] = $stateData['subdomain'];
        }

        // Pipedrive: store api_domain from token response
        // Fix 6: validate host to prevent SSRF via compromised token endpoint
        if ($driver === 'pipedrive' && ! empty($tokens['api_domain'])) {
            $apiDomain = rtrim((string) $tokens['api_domain'], '/');
            $host = (string) parse_url($apiDomain, PHP_URL_HOST);
            if ($host && str_ends_with($host, '.pipedrive.com')) {
                $credentials['api_domain'] = $apiDomain;
            } else {
                Log::warning('OAuthCallbackAction: rejected invalid Pipedrive api_domain', ['host' => $host]);
            }
        }

        // Asana: store workspace GID for API calls
        if ($driver === 'asana') {
            try {
                $workspaces = Http::withToken($credentials['access_token'])
                    ->timeout(10)
                    ->get('https://app.asana.com/api/1.0/workspaces')
                    ->json();

                $workspaceGid = $workspaces['data'][0]['gid'] ?? null;
                if ($workspaceGid) {
                    $credentials['workspace_gid'] = (string) $workspaceGid;
                }
            } catch (\Throwable $e) {
                Log::warning('OAuthCallbackAction: could not fetch Asana workspaces', ['error' => $e->getMessage()]);
            }
        }

        return $credentials;
    }
}
