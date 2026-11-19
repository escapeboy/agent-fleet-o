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

    /**
     * Replace emails, IBANs and Luhn-valid card numbers with numbered
     * placeholders ([EMAIL_1], [IBAN_1], [CARD_1]). Numbering is per distinct
     * value and shared across all given texts, so the same address gets the
     * same placeholder in the system and the user prompt. Counts are the number
     * of distinct values per type; the values themselves are never returned.
     *
     * @param  array<int|string, string>  $texts
     * @return array{texts: list<string>, counts: array{email: int, iban: int, card: int}}
     */
    public function redactAll(array $texts): array
    {
        /** @var array{email: array<string, string>, iban: array<string, string>, card: array<string, string>} $seen */
        $seen = ['email' => [], 'iban' => [], 'card' => []];

        $placeholder = function (string $type, string $label, string $key) use (&$seen): string {
            if (! isset($seen[$type][$key])) {
                $seen[$type][$key] = '['.$label.'_'.(count($seen[$type]) + 1).']';
            }

            return $seen[$type][$key];
        };

        $out = [];

        foreach (array_values($texts) as $text) {
            $text = preg_replace_callback(
                self::EMAIL,
                fn (array $m): string => $placeholder('email', 'EMAIL', strtolower($m[0])),
                $text,
            ) ?? $text;

            $text = preg_replace_callback(
                self::IBAN,
                fn (array $m): string => $placeholder('iban', 'IBAN', $m[0]),
                $text,
            ) ?? $text;

            $text = preg_replace_callback(
                self::CARD_CANDIDATE,
                function (array $m) use ($placeholder): string {
                    $digits = preg_replace('/\D/', '', $m[0]) ?? '';

                    if (strlen($digits) < 13 || strlen($digits) > 19 || ! $this->luhnValid($digits)) {
                        return $m[0];
                    }

                    // The candidate pattern may swallow one trailing separator; keep it.
                    $trimmed = rtrim($m[0], ' -');

                    return $placeholder('card', 'CARD', $digits).substr($m[0], strlen($trimmed));
                },
                $text,
            ) ?? $text;

            $out[] = $text;
        }

        return [
            'texts' => $out,
            'counts' => [
                'email' => count($seen['email']),
                'iban' => count($seen['iban']),
                'card' => count($seen['card']),
            ],
        ];
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
