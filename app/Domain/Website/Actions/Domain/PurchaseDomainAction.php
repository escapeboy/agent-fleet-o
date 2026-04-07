<?php

namespace App\Domain\Website\Actions\Domain;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Services\NamecheapClientFactory;

class PurchaseDomainAction
{
    /**
     * Purchase a domain via Namecheap and attach it to the given website.
     *
     * @param  array<string, mixed>  $contact  Registrant contact details + optional 'years'
     * @return array{success: bool, domain: string, transaction_id: string|null}
     */
    public function execute(Team $team, Website $website, string $domain, array $contact): array
    {
        $client = (new NamecheapClientFactory)->forTeam($team);

        $result = $client->purchaseDomain($domain, $contact);

        if ($result['success']) {
            $website->update(['custom_domain' => $domain]);
        }

        return array_merge(['domain' => $domain], $result);
    }
}
