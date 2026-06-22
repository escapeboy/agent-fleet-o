<?php

namespace App\Infrastructure\AI\Guardrails\Scanners;

use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;

/**
 * Flags http(s) URLs whose host is not on the configured allowlist. Off by
 * default; intended for output scanning where exfiltration links are a concern.
 *
 * @param  list<string>  $allowlist  host suffixes considered safe
 */
class UrlScanner implements ScannerInterface
{
    private const URL = '~https?://([A-Za-z0-9.\-]+)(?:[:/?#]\S*)?~i';

    /**
     * @param  list<string>  $allowlist
     */
    public function __construct(
        private readonly string $severity = 'low',
        private readonly array $allowlist = [],
    ) {}

    public function id(): string
    {
        return 'url';
    }

    public function scan(string $content, string $direction): ?ScannerHit
    {
        if ($content === '') {
            return null;
        }

        if (preg_match_all(self::URL, $content, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $host) {
            if (! $this->isAllowed($host)) {
                return new ScannerHit($this->id(), $this->severity, 'external URL: '.$host);
            }
        }

        return null;
    }

    private function isAllowed(string $host): bool
    {
        $host = strtolower($host);

        foreach ($this->allowlist as $allowed) {
            $allowed = strtolower($allowed);

            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
