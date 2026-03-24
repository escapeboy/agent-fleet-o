<?php

namespace App\Domain\Integration\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Begin an OAuth2 authorization flow for an integration driver.
 *
 * Stores a one-time state token in Redis (10-minute TTL) keyed to
 * the team, driver, and desired integration name, then returns the
 * provider's authorization URL to redirect the user to.
 *
 * Supports PKCE (S256) for drivers that require it (e.g. Airtable).
 * Supports subdomain-based authorization URLs (e.g. Zendesk, Freshdesk).
 */
class OAuthConnectAction
{
    /**
     * @param  string|null  $subdomain  Required for subdomain-based providers (Zendesk, Freshdesk)
     */
    public function execute(string $teamId, string $driver, string $name, ?string $subdomain = null): string
    {
        $oauthConfig = config("integrations.oauth.{$driver}");
        $authorizeUrl = $this->resolveAuthorizeUrl($driver, $subdomain);

        if (! $oauthConfig || ! $authorizeUrl) {
            throw new \InvalidArgumentException("No OAuth2 configuration found for driver: {$driver}");
        }

        $state = Str::uuid()->toString();

        // PKCE: generate code_verifier / code_challenge for drivers that require it (e.g. Airtable)
        $codeVerifier = null;
        if (! empty($oauthConfig['pkce'])) {
            $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }

        Cache::store('redis')->put(
            "integration_oauth_state:{$state}",
            [
                'team_id' => $teamId,
                'driver' => $driver,
                'name' => $name,
                'subdomain' => $subdomain,
                'code_verifier' => $codeVerifier,
            ],
            now()->addMinutes(10),
        );

        $params = [
            'client_id' => $oauthConfig['client_id'],
            'redirect_uri' => route('integrations.oauth.callback', $driver),
            'response_type' => 'code',
            'state' => $state,
        ];

        if (! empty($oauthConfig['scopes'])) {
            $params['scope'] = implode(' ', $oauthConfig['scopes']);
        }

        // Driver-specific extra query params (e.g. Atlassian needs audience + prompt=consent)
        if (! empty($oauthConfig['extra_params'])) {
            $params = array_merge($params, $oauthConfig['extra_params']);
        }

        // PKCE: add code_challenge parameters
        if ($codeVerifier !== null) {
            $params['code_challenge'] = rtrim(
                strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'),
                '=',
            );
            $params['code_challenge_method'] = 'S256';
        }

        return $authorizeUrl.'?'.http_build_query($params);
    }

    /**
     * Resolve the authorization URL, handling subdomain-based providers.
     */
    private function resolveAuthorizeUrl(string $driver, ?string $subdomain): ?string
    {
        $configured = config("integrations.oauth_urls.{$driver}.authorize");

        if ($configured) {
            return $configured;
        }

        // Subdomain-based providers
        if ($subdomain) {
            return match ($driver) {
                'zendesk' => "https://{$subdomain}.zendesk.com/oauth/authorizations/new",
                'freshdesk' => "https://{$subdomain}.freshdesk.com/oauth/authorize",
                default => null,
            };
        }

        return null;
    }
}
