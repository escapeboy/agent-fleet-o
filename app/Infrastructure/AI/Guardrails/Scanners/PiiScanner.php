<?php

namespace App\Infrastructure\AI\Guardrails\Scanners;

use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;

/**
 * Conservative structured-PII detector: email, IBAN, and Luhn-valid card
 * numbers. Deliberately omits free-form phone numbers (high false-positive
 * rate); defaults to output-only scanning via config.
 */
class PiiScanner implements ScannerInterface
{
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    private const IBAN = '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/';

    private const CARD_CANDIDATE = '/\b(?:\d[ \-]?){13,19}\b/';

    public function __construct(private readonly string $severity = 'high') {}

    public function id(): string
    {
        return 'pii';
    }

    public function scan(string $content, string $direction): ?ScannerHit
    {
        if ($content === '') {
            return null;
        }

        if (preg_match(self::EMAIL, $content)) {
            return new ScannerHit($this->id(), $this->severity, 'email address');
        }

        if (preg_match(self::IBAN, $content)) {
            return new ScannerHit($this->id(), $this->severity, 'IBAN');
        }

        if (preg_match_all(self::CARD_CANDIDATE, $content, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                $digits = preg_replace('/\D/', '', $candidate) ?? '';

                if (strlen($digits) >= 13 && strlen($digits) <= 19 && $this->luhnValid($digits)) {
                    return new ScannerHit($this->id(), $this->severity, 'payment card number');
                }
            }
        }

        return null;
    }

    private function luhnValid(string $digits): bool
    {
        $sum = 0;
        $alt = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($alt) {
                $n *= 2;

                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
