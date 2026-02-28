<?php

namespace App\Domain\Shared\Services;

/**
 * SSRF Guard — blocks HTTP requests to RFC 1918 private networks and loopback.
 *
 * Used by all outbound HTTP connectors (webhook, Slack, Google Chat, Matrix,
 * Signal Protocol) and the inbound RSS connector to prevent Server-Side Request
 * Forgery attacks where a tenant-supplied URL could reach internal infrastructure.
 *
 * Only enforced when services.ssrf.validate_host = true (forced in cloud mode via
 * CloudServiceProvider). Defaults to false in community edition for backward compat.
 */
class SsrfGuard
{
    private const BLOCKED_CIDRS = [
        ['127.0.0.0', 8],    // Loopback
        ['10.0.0.0', 8],     // RFC 1918 Class A
        ['172.16.0.0', 12],  // RFC 1918 Class B
        ['192.168.0.0', 16], // RFC 1918 Class C
        ['169.254.0.0', 16], // Link-local (AWS metadata, Azure IMDS)
        ['100.64.0.0', 10],  // Shared address space (RFC 6598)
        ['::1', 128],        // IPv6 loopback
        ['fc00::', 7],       // IPv6 unique local
        ['fe80::', 10],      // IPv6 link-local
    ];

    /**
     * Validate that the URL's host is a public, routable address.
     *
     * @throws \InvalidArgumentException if the scheme is not http/https or host resolves to a private IP
     */
    public function assertPublicUrl(string $url): void
    {
        if (! config('services.ssrf.validate_host', false)) {
            return;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("URL scheme '{$scheme}' is not allowed. Only http/https are permitted.");
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            throw new \InvalidArgumentException("Cannot parse host from URL: {$url}");
        }

        $this->assertPublicHost($host);
    }

    /**
     * Validate that a hostname resolves only to public IP addresses.
     *
     * @throws \InvalidArgumentException if the host resolves to a private/internal address
     */
    public function assertPublicHost(string $host): void
    {
        if (! config('services.ssrf.validate_host', false)) {
            return;
        }

        // Resolve to IPs (pass-through if already an IP)
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ips = [$host];
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->assertPublicIpv6($host);
            return;
        } else {
            // DNS resolution — note: DNS rebinding is still possible if TTL=0.
            // For production, pair this with network-level egress controls.
            $ipv4Records = array_column(dns_get_record($host, DNS_A) ?: [], 'ip');
            $ipv6Records = array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');
            $ips = $ipv4Records;

            foreach ($ipv6Records as $ipv6) {
                $this->assertPublicIpv6($ipv6);
            }
        }

        if (empty($ips)) {
            throw new \InvalidArgumentException("Host '{$host}' could not be resolved or has no A records.");
        }

        foreach ($ips as $ip) {
            $ipLong = ip2long($ip);
            if ($ipLong === false) {
                continue;
            }

            foreach (self::BLOCKED_CIDRS as [$network, $prefix]) {
                if (! str_contains($network, ':')) { // IPv4 only in this loop
                    $mask = ~((1 << (32 - $prefix)) - 1);
                    if (($ipLong & $mask) === (ip2long($network) & $mask)) {
                        throw new \InvalidArgumentException(
                            "Host '{$host}' resolves to a private or internal address and is not allowed."
                        );
                    }
                }
            }
        }
    }

    private function assertPublicIpv6(string $ip): void
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return;
        }

        // ::1 loopback
        if ($ip === '::1') {
            throw new \InvalidArgumentException("Host '{$ip}' is a loopback address and is not allowed.");
        }

        // fc00::/7 (unique local) and fe80::/10 (link-local)
        $first2 = unpack('n', substr($packed, 0, 2))[1];
        if (($first2 & 0xfe00) === 0xfc00 || ($first2 & 0xffc0) === 0xfe80) {
            throw new \InvalidArgumentException(
                "Host '{$ip}' is a private or link-local IPv6 address and is not allowed."
            );
        }
    }
}
