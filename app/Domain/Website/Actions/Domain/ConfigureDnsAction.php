<?php

namespace App\Domain\Website\Actions\Domain;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Services\NamecheapClientFactory;

class ConfigureDnsAction
{
    /**
     * Point a website's custom domain (@ and www) to the given IP via Namecheap DNS.
     */
    public function execute(Team $team, Website $website, string $ipAddress): bool
    {
        $domain = $website->custom_domain;

        if (! $domain) {
            return false;
        }

        [$sld, $tld] = $this->splitDomain($domain);

        $client = (new NamecheapClientFactory)->forTeam($team);

        return $client->setDnsHosts($sld, $tld, [
            ['name' => '@', 'type' => 'A', 'value' => $ipAddress, 'ttl' => 300],
            ['name' => 'www', 'type' => 'A', 'value' => $ipAddress, 'ttl' => 300],
        ]);
    }

    /**
     * Split a domain like "example.co.uk" into SLD "example" and TLD "co.uk".
     *
     * @return array{string, string}
     */
    private function splitDomain(string $domain): array
    {
        $parts = explode('.', $domain, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
