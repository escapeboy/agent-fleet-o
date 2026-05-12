<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the `/metrics` endpoint to internal-network callers.
 *
 * Allowed by default:
 *   - 127.0.0.0/8 (localhost)
 *   - 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 (RFC 1918 private)
 *
 * Plus any IPs listed (comma-separated) in
 * `observability.prometheus.metrics_allowed_ips`. Each entry may be a bare IP
 * or CIDR (e.g. `203.0.113.0/24`).
 *
 * NOTE: this is a defense-in-depth layer. Prometheus is also supposed to scrape
 * the endpoint only from inside the Docker network (verified by docker-compose
 * service-to-service network isolation). If the endpoint is ever exposed
 * publicly, the IP allowlist is the last line of defence.
 */
final class InternalNetworkOnly
{
    private const DEFAULT_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '::1/128',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->getClientIp();
        if ($clientIp === null) {
            abort(403, 'Forbidden — no client IP resolved.');
        }

        $allowed = array_merge(self::DEFAULT_RANGES, $this->extraAllowedRanges());

        if (! IpUtils::checkIp($clientIp, $allowed)) {
            abort(403, 'Forbidden — caller is not on an allowed internal network.');
        }

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    private function extraAllowedRanges(): array
    {
        $raw = (string) config('observability.prometheus.metrics_allowed_ips', '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
