<?php

namespace App\Domain\Website\Services;

use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use RuntimeException;

class NamecheapClientFactory
{
    /**
     * Resolve Namecheap credentials for the given team and return a configured client.
     *
     * The team must have a Credential with slug 'namecheap' or a metadata key service='namecheap'.
     * The credential's secret_data must contain: api_key, api_user, username, client_ip.
     * Optionally: sandbox (bool).
     */
    public function forTeam(Team $team): NamecheapClient
    {
        /** @var Credential|null $credential */
        $credential = Credential::query()
            ->where('team_id', $team->id)
            ->where(function ($q) {
                $q->where('slug', 'namecheap')
                    ->orWhere('name', 'like', '%namecheap%');
            })
            ->first();

        if (! $credential) {
            throw new RuntimeException('No Namecheap credential found for this team. Please add a credential named "namecheap".');
        }

        $secret = $credential->secret_data;

        foreach (['api_key', 'api_user', 'username', 'client_ip'] as $required) {
            if (empty($secret[$required])) {
                throw new RuntimeException("Namecheap credential is missing required field: {$required}");
            }
        }

        return new NamecheapClient(
            apiUser: $secret['api_user'],
            apiKey: $secret['api_key'],
            username: $secret['username'],
            clientIp: $secret['client_ip'],
            sandbox: (bool) ($secret['sandbox'] ?? false),
        );
    }
}
