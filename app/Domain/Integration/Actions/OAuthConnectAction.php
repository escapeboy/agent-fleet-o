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
 */
class OAuthConnectAction
{
    public function execute(string $teamId, string $driver, string $name): string
    {
        $oauthConfig = config("integrations.oauth.{$driver}");
        $authorizeUrl = config("integrations.oauth_urls.{$driver}.authorize");

        if (! $oauthConfig || ! $authorizeUrl) {
            throw new \InvalidArgumentException("No OAuth2 configuration found for driver: {$driver}");
        }

        $state = Str::uuid()->toString();

        Cache::store('redis')->put(
            "integration_oauth_state:{$state}",
            ['team_id' => $teamId, 'driver' => $driver, 'name' => $name],
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

        return $authorizeUrl.'?'.http_build_query($params);
    }
}
