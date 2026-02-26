<?php

namespace App\Infrastructure\AI\Services;

use InvalidArgumentException;

class LocalLlmUrlValidator
{
    /**
     * Link-local and reserved CIDRs blocked under SSRF protection.
     * Private ranges (10.x, 192.168.x, 172.16-31.x) are intentionally allowed
     * because users commonly run Ollama on LAN machines.
     *
     * @var list<string>
     */
    private const BLOCKED_CIDRS = [
        '169.254.0.0/16',  // Link-local (incl. AWS metadata 169.254.169.254)
        '100.64.0.0/10',   // Shared address space (RFC 6598)
        '192.0.2.0/24',    // TEST-NET-1 (RFC 5737)
        '198.51.100.0/24', // TEST-NET-2
        '203.0.113.0/24',  // TEST-NET-3
    ];

    public function validate(string $url): void
    {
        $parsed = parse_url($url);

        if (! $parsed || empty($parsed['host'])) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        if (! in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            throw new InvalidArgumentException('Local LLM URL must use http or https scheme.');
        }

        if (! config('local_llm.ssrf_protection', true)) {
            return;
        }

        $host = $parsed['host'];

        // Always allow loopback — required for truly local models
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $host : @gethostbyname($host);

        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv6 or DNS unresolvable — allow and let the HTTP client handle it
            return;
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                throw new InvalidArgumentException(
                    'The provided URL points to a restricted IP range. '
                    .'Set LOCAL_LLM_SSRF_PROTECTION=false to allow private network addresses.',
                );
            }
        }
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $mask = ~((1 << (32 - (int) $bits)) - 1);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}
