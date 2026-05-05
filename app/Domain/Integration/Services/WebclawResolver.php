<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WebclawResolver
{
    /**
     * Resolve webclaw HTTP client and base URL for a team.
     * If team has a connected webclaw integration → use cloud API with their key.
     * Otherwise → use local self-hosted server (no auth).
     *
     * Returns ['url' => string, 'http' => PendingRequest]
     *
     * @return array{url: string, http: PendingRequest}
     */
    public static function forTeam(?string $teamId): array
    {
        $apiKey = static::resolveApiKey($teamId);

        $url = $apiKey
            ? config('services.webclaw.cloud_url', 'https://api.webclaw.io')
            : config('services.webclaw.url', 'http://webclaw:3000');

        $http = Http::timeout(30);
        if ($apiKey) {
            $http = $http->withToken($apiKey);
        }

        return ['url' => $url, 'http' => $http];
    }

    private static function resolveApiKey(?string $teamId): ?string
    {
        if ($teamId) {
            $integration = Integration::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('driver', 'webclaw')
                ->first();

            $key = $integration?->getCredentialSecret('api_key');
            if ($key) {
                return $key;
            }
        }

        return config('services.webclaw.api_key') ?: null;
    }
}
