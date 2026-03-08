<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Shared\Services\SsrfGuard;
use InvalidArgumentException;

/**
 * Validates user-supplied custom AI endpoint URLs.
 *
 * Self-hosted mode: allows any valid http/https URL including private IPs and localhost.
 * Cloud mode: delegates to SsrfGuard which blocks private/internal IP ranges.
 */
class CustomEndpointUrlValidator
{
    public function validate(string $url): void
    {
        $parsed = parse_url($url);

        if (! $parsed || empty($parsed['host'])) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        if (! in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            throw new InvalidArgumentException('Custom endpoint URL must use http or https scheme.');
        }

        // Reject credentials in URL (SSRF / auth bypass)
        if (! empty($parsed['user']) || ! empty($parsed['pass'])) {
            throw new InvalidArgumentException('Custom endpoint URL must not contain credentials.');
        }

        // In cloud mode apply full SSRF protection (blocks private/internal IPs, requires public hosts)
        if ($this->isCloudMode()) {
            app(SsrfGuard::class)->assertPublicUrl($url);
        }
    }

    private function isCloudMode(): bool
    {
        return config('services.ssrf.validate_host', false);
    }
}
