<?php

namespace App\Infrastructure\AI\Guardrails\Contracts;

use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;

interface ScannerInterface
{
    /**
     * Stable scanner key — surfaces in the violation payload as scanner:<id>.
     */
    public function id(): string;

    /**
     * Inspect content travelling in the given direction ('input' | 'output').
     * Returns the first hit, or null when clean. Implementations MUST be pure
     * (no network, no LLM) and MUST NOT throw for malformed input — the caller
     * treats any throw as fail-open, but scanners should degrade to null.
     */
    public function scan(string $content, string $direction): ?ScannerHit;
}
