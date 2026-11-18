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
     * value and shared across all given texts, so the same value maps to the
     * same placeholder everywhere. Counts are distinct values per type.
     *
     * @param  array<int, string>  $texts
     * @return array{texts: list<string>, counts: array{email: int, iban: int, card: int}}
     */
    public function redactAll(array $texts): array
    {
        $texts = array_values($texts);

        /** @var array{email: array<string, string>, iban: array<string, string>, card: array<string, string>} $maps */
        $maps = ['email' => [], 'iban' => [], 'card' => []];

        $placeholder = function (string $type, string $key) use (&$maps): string {
            if (! isset($maps[$type][$key])) {
                $maps[$type][$key] = '['.strtoupper($type).'_'.(count($maps[$type]) + 1).']';
            }

            return $maps[$type][$key];
        };

        foreach ($texts as $i => $text) {
            $texts[$i] = $this->replace(self::EMAIL, $text, fn (string $match): string => $placeholder('email', $match));
        }

        foreach ($texts as $i => $text) {
            $texts[$i] = $this->replace(self::IBAN, $text, fn (string $match): string => $placeholder('iban', $match));
        }

        foreach ($texts as $i => $text) {
            $texts[$i] = $this->replace(self::CARD_CANDIDATE, $text, function (string $match) use ($placeholder): string {
                $digits = preg_replace('/\D/', '', $match) ?? '';

                if (strlen($digits) < 13 || strlen($digits) > 19 || ! $this->luhnValid($digits)) {
                    return $match;
                }

                // The candidate pattern can swallow a trailing separator; keep it in the text.
                $trimmed = rtrim($match, ' -');

                return $placeholder('card', $digits).substr($match, strlen($trimmed));
            });
        }

        return [
            'texts' => $texts,
            'counts' => [
                'email' => count($maps['email']),
                'iban' => count($maps['iban']),
                'card' => count($maps['card']),
            ],
        ];
    }

    /**
     * @param  callable(string): string  $replacer
     */
    private function replace(string $pattern, string $text, callable $replacer): string
    {
        if ($text === '') {
            return $text;
        }

        $result = preg_replace_callback($pattern, fn (array $m): string => $replacer($m[0]), $text);

        if ($result === null) {
            throw new \RuntimeException('PII redaction failed: regex error '.preg_last_error());
        }

        return $result;
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
