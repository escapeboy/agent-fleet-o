<?php

namespace App\Infrastructure\AI\Guardrails\Scanners;

use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;

/**
 * Flags oversized base64 / data-URI blobs in output — a common shape for
 * smuggling binary payloads or large encoded data out through a model reply.
 */
class CodeFenceExfilScanner implements ScannerInterface
{
    public function __construct(
        private readonly string $severity = 'medium',
        private readonly int $minBytes = 512,
    ) {}

    public function id(): string
    {
        return 'code_exfil';
    }

    public function scan(string $content, string $direction): ?ScannerHit
    {
        if ($content === '') {
            return null;
        }

        $dataUri = '/data:[\w.+\-]+\/[\w.+\-]+;base64,[A-Za-z0-9+\/]{'.$this->minBytes.',}={0,2}/';
        if (preg_match($dataUri, $content)) {
            return new ScannerHit($this->id(), $this->severity, 'base64 data-URI blob');
        }

        $rawBlob = '/[A-Za-z0-9+\/]{'.$this->minBytes.',}={0,2}/';
        if (preg_match($rawBlob, $content)) {
            return new ScannerHit($this->id(), $this->severity, 'large base64 blob');
        }

        return null;
    }
}
