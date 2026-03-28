<?php

namespace App\Infrastructure\Security;

use App\Infrastructure\Security\DTOs\IpReputationResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IpReputationService
{
    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    private const PRIVATE_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '::1/128',
        'fc00::/7',
    ];

    public function check(string $ip): IpReputationResult
    {
        if ($this->isPrivate($ip)) {
            return new IpReputationResult(
                ip: $ip,
                abuseScore: 0,
                isTor: false,
                isVpn: false,
                countryCode: null,
                fromCache: false,
            );
        }

        $cacheKey = 'ip_reputation:'.$ip;

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return new IpReputationResult(
                ip: $ip,
                abuseScore: $cached['abuse_score'],
                isTor: $cached['is_tor'],
                isVpn: $cached['is_vpn'],
                countryCode: $cached['country_code'],
                fromCache: true,
            );
        }

        return $this->fetchAndCache($ip, $cacheKey);
    }

    public function isPrivate(string $ip): bool
    {
        // Treat loopback and unresolvable addresses as private.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        foreach (self::PRIVATE_RANGES as $range) {
            if ($this->ipInCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function fetchAndCache(string $ip, string $cacheKey): IpReputationResult
    {
        $apiKey = config('security.ip_reputation.abuseipdb_key');

        if (empty($apiKey)) {
            return $this->failOpen($ip, 'AbuseIPDB API key not configured');
        }

        try {
            $response = Http::withHeaders([
                'Key' => $apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(5)
                ->get('https://api.abuseipdb.com/api/v2/check', [
                    'ipAddress' => $ip,
                    'maxAgeInDays' => 90,
                    'verbose' => false,
                ]);

            if (! $response->successful()) {
                return $this->failOpen($ip, 'AbuseIPDB returned HTTP '.$response->status());
            }

            $data = $response->json('data', []);

            $result = [
                'abuse_score' => (int) ($data['abuseConfidenceScore'] ?? 0),
                'is_tor' => (bool) ($data['isTor'] ?? false),
                'is_vpn' => (bool) in_array($data['usageType'] ?? '', ['VPN Service', 'Hosting/Data Center']),
                'country_code' => $data['countryCode'] ?? null,
            ];

            Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);

            return new IpReputationResult(
                ip: $ip,
                abuseScore: $result['abuse_score'],
                isTor: $result['is_tor'],
                isVpn: $result['is_vpn'],
                countryCode: $result['country_code'],
                fromCache: false,
            );
        } catch (\Throwable $e) {
            return $this->failOpen($ip, $e->getMessage());
        }
    }

    private function failOpen(string $ip, string $reason): IpReputationResult
    {
        Log::warning('IpReputationService: fail-open', ['ip' => $ip, 'reason' => $reason]);

        // Cache fail-open results briefly to avoid hammering AbuseIPDB during outages.
        Cache::put('ip_reputation:'.$ip, [
            'abuse_score' => 0,
            'is_tor' => false,
            'is_vpn' => false,
            'country_code' => null,
        ], 120);

        return new IpReputationResult(
            ip: $ip,
            abuseScore: 0,
            isTor: false,
            isVpn: false,
            countryCode: null,
            fromCache: false,
        );
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixLen] = explode('/', $cidr);

        // IPv6
        if (str_contains($cidr, ':')) {
            if (! str_contains($ip, ':')) {
                return false;
            }

            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);

            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $prefixBytes = (int) ceil((int) $prefixLen / 8);
            $maskBits = (int) $prefixLen % 8;

            for ($i = 0; $i < $prefixBytes - 1; $i++) {
                if ($ipBin[$i] !== $subnetBin[$i]) {
                    return false;
                }
            }

            if ($maskBits > 0) {
                $mask = 0xFF & (0xFF << (8 - $maskBits));

                return (ord($ipBin[$prefixBytes - 1]) & $mask) === (ord($subnetBin[$prefixBytes - 1]) & $mask);
            }

            return true;
        }

        // IPv4
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = ~((1 << (32 - (int) $prefixLen)) - 1);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
