<?php

namespace App\Infrastructure\AI\Guardrails\DTOs;

final readonly class ScannerHit
{
    public function __construct(
        public string $scannerId,
        public string $severity,
        public string $snippet,
    ) {}
}
