<?php

namespace App\Domain\Website\Actions\Domain;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Services\NamecheapClientFactory;

class CheckDomainAvailabilityAction
{
    /**
     * Check whether a domain name is available for registration.
     *
     * @return array{available: bool, domain: string, price: float|null}
     */
    public function execute(Team $team, string $domain): array
    {
        $client = (new NamecheapClientFactory)->forTeam($team);

        $result = $client->checkDomain($domain);

        return array_merge(['domain' => $domain], $result);
    }
}
