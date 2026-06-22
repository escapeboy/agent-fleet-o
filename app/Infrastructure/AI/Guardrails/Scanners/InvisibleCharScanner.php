<?php

namespace App\Infrastructure\AI\Guardrails\Scanners;

use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;

/**
 * Flags hidden Unicode used for ASCII-smuggling / invisible prompt injection:
 * zero-width characters, bidi overrides/isolates, BOM, and the Unicode tag
 * block (U+E0000–U+E007F) which can encode an entire instruction invisibly.
 * Generic regex/contains rule packs cannot see these — that is the gap this fills.
 */
class InvisibleCharScanner implements ScannerInterface
{
    private const PATTERN = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{E0000}-\x{E007F}]/u';

    public function __construct(private readonly string $severity = 'high') {}

    public function id(): string
    {
        return 'invisible_chars';
    }

    public function scan(string $content, string $direction): ?ScannerHit
    {
        if ($content === '') {
            return null;
        }

        if (@preg_match(self::PATTERN, $content, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = max(0, $matches[0][1] - 20);
        $snippet = '[invisible unicode] '.mb_substr(preg_replace(self::PATTERN, '?', mb_strcut($content, $offset, 120)) ?? '', 0, 120);

        return new ScannerHit($this->id(), $this->severity, $snippet);
    }
}
