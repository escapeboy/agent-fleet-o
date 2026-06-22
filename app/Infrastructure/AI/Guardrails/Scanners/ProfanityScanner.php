<?php

namespace App\Infrastructure\AI\Guardrails\Scanners;

use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;

/**
 * Configurable wordlist matcher (toxicity-lite). Empty and disabled by default;
 * teams supply their own terms via config('ai_safety.scanners.profanity.words').
 *
 * @param  list<string>  $words
 */
class ProfanityScanner implements ScannerInterface
{
    /**
     * @param  list<string>  $words
     */
    public function __construct(
        private readonly string $severity = 'low',
        private readonly array $words = [],
    ) {}

    public function id(): string
    {
        return 'profanity';
    }

    public function scan(string $content, string $direction): ?ScannerHit
    {
        if ($content === '' || $this->words === []) {
            return null;
        }

        foreach ($this->words as $word) {
            if ($word !== '' && mb_stripos($content, $word) !== false) {
                return new ScannerHit($this->id(), $this->severity, 'flagged term');
            }
        }

        return null;
    }
}
