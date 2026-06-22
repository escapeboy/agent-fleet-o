<?php

namespace App\Infrastructure\AI\Guardrails\Scanners;

use App\Domain\Credential\Services\SecretPatternLibrary;
use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;

/**
 * Detects leaked provider API keys / tokens in gateway traffic. Reuses the
 * existing SecretPatternLibrary (Credential domain) rather than maintaining a
 * second copy of secret patterns.
 */
class SecretScanner implements ScannerInterface
{
    public function __construct(
        private readonly SecretPatternLibrary $library,
        private readonly string $severity = 'critical',
    ) {}

    public function id(): string
    {
        return 'secrets';
    }

    public function scan(string $content, string $direction): ?ScannerHit
    {
        if ($content === '') {
            return null;
        }

        $findings = $this->library->scan($content);

        if ($findings === []) {
            return null;
        }

        return new ScannerHit($this->id(), $this->severity, 'detected '.$findings[0]['name']);
    }
}
