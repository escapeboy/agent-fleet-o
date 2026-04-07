<?php

namespace App\Domain\Website\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class NamecheapClient
{
    private string $baseUrl;

    public function __construct(
        private string $apiUser,
        private string $apiKey,
        private string $username,
        private string $clientIp,
        private bool $sandbox = false,
    ) {
        $this->baseUrl = $sandbox
            ? 'https://api.sandbox.namecheap.com/xml.response'
            : 'https://api.namecheap.com/xml.response';
    }

    /**
     * Check domain availability.
     *
     * @return array{available: bool, price: float|null}
     */
    public function checkDomain(string $domain): array
    {
        $xml = $this->call('namecheap.domains.check', ['DomainList' => $domain]);

        $result = $xml->CommandResponse->DomainCheckResult ?? null;

        if (! $result) {
            return ['available' => false, 'price' => null];
        }

        $available = strtolower((string) $result->attributes()->Available) === 'true';
        $price = null;

        if ($available && isset($result->attributes()->PremiumRegistrationPrice)) {
            $raw = (string) $result->attributes()->PremiumRegistrationPrice;
            $price = $raw !== '' ? (float) $raw : null;
        }

        return ['available' => $available, 'price' => $price];
    }

    /**
     * Purchase a domain.
     *
     * @param  array<string, string>  $contact  Registrant contact fields
     * @return array{success: bool, transaction_id: string|null}
     */
    public function purchaseDomain(string $domain, array $contact): array
    {
        $params = array_merge(['DomainName' => $domain, 'Years' => $contact['years'] ?? 1], $this->buildContactParams($contact));

        $xml = $this->call('namecheap.domains.create', $params);

        $result = $xml->CommandResponse->DomainCreateResult ?? null;

        if (! $result) {
            return ['success' => false, 'transaction_id' => null];
        }

        $success = strtolower((string) $result->attributes()->Registered) === 'true';
        $transactionId = isset($result->attributes()->TransactionID)
            ? (string) $result->attributes()->TransactionID
            : null;

        return ['success' => $success, 'transaction_id' => $transactionId];
    }

    /**
     * Set DNS host records for a domain.
     *
     * @param  array<int, array{name: string, type: string, value: string, ttl: int}>  $hosts
     */
    public function setDnsHosts(string $sld, string $tld, array $hosts): bool
    {
        $params = ['SLD' => $sld, 'TLD' => $tld];

        foreach ($hosts as $i => $host) {
            $n = $i + 1;
            $params["HostName{$n}"] = $host['name'];
            $params["RecordType{$n}"] = $host['type'];
            $params["Address{$n}"] = $host['value'];
            $params["TTL{$n}"] = $host['ttl'] ?? 300;
        }

        $xml = $this->call('namecheap.domains.dns.setHosts', $params);

        $result = $xml->CommandResponse->DomainDNSSetHostsResult ?? null;

        if (! $result) {
            return false;
        }

        return strtolower((string) $result->attributes()->IsSuccess) === 'true';
    }

    /**
     * Execute a Namecheap API command and return parsed XML.
     *
     * @param  array<string, mixed>  $params
     */
    private function call(string $command, array $params = []): SimpleXMLElement
    {
        $query = array_merge([
            'ApiUser' => $this->apiUser,
            'ApiKey' => $this->apiKey,
            'UserName' => $this->username,
            'ClientIp' => $this->clientIp,
            'Command' => $command,
        ], $params);

        $response = Http::get($this->baseUrl, $query);

        if ($response->failed()) {
            throw new RuntimeException("Namecheap API HTTP error: {$response->status()}");
        }

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            throw new RuntimeException('Namecheap API returned invalid XML.');
        }

        $status = (string) $xml->attributes()->Status;

        if ($status === 'ERROR') {
            $errors = [];
            foreach ($xml->Errors->Error ?? [] as $error) {
                $errors[] = (string) $error;
            }
            throw new RuntimeException('Namecheap API error: '.implode('; ', $errors));
        }

        return $xml;
    }

    /**
     * Map contact array keys to Namecheap registrant param names.
     *
     * @param  array<string, string>  $contact
     * @return array<string, string>
     */
    private function buildContactParams(array $contact): array
    {
        $mapping = [
            'first_name' => 'RegistrantFirstName',
            'last_name' => 'RegistrantLastName',
            'address1' => 'RegistrantAddress1',
            'city' => 'RegistrantCity',
            'state_province' => 'RegistrantStateProvince',
            'postal_code' => 'RegistrantPostalCode',
            'country' => 'RegistrantCountry',
            'phone' => 'RegistrantPhone',
            'email_address' => 'RegistrantEmailAddress',
        ];

        $result = [];
        foreach ($mapping as $key => $param) {
            if (isset($contact[$key])) {
                $result[$param] = $contact[$key];
            }
        }

        return $result;
    }
}
