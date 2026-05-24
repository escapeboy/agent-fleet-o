<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

/**
 * Outcome of a deterministic brand-voice check.
 */
final readonly class BrandVoiceResult
{
    /**
     * @param  list<string>  $violations  human-readable violation messages
     */
    public function __construct(
        public bool $passed,
        public array $violations = [],
    ) {}
}
