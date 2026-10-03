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

    /**
     * For third-party content read by a model: ZWNJ/ZWJ (U+200C–U+200D, emoji
     * sequences, Persian and Indic scripts) and all bidi controls (U+200E–U+200F,
     * U+202A–U+202E, U+2066–U+2069) are allowed. Web pages carry them routinely
     * (e.g. MediaWiki wraps language names in U+202A…U+202C), and bidi controls
     * only change how text is displayed to a person; they hide nothing from the
     * model. Still flagged: the tag block, zero-width space, word joiner and
     * invisible operators, deprecated format characters, and a BOM inside text.
     */
    private const PATTERN_ALLOW_TEXT_MARKS = '/[\x{200B}\x{2060}-\x{2064}\x{206A}-\x{206F}\x{FEFF}\x{E0000}-\x{E007F}]/u';

    /**
     * @param  bool  $allowTextMarks  allow joiners, bidi controls and a leading BOM (for third-party content such as tool output)
     */
    public function __construct(
        private readonly string $severity = 'high',
        private readonly bool $allowTextMarks = false,
    ) {}

    public function id(): string
    {
        return 'invisible_chars';
    }

    public function scan(string $content, string $direction): ?ScannerHit
    {
        if ($content === '') {
            return null;
        }

        $pattern = self::PATTERN;
        if ($this->allowTextMarks) {
            $pattern = self::PATTERN_ALLOW_TEXT_MARKS;
            // A byte-order mark at the very start is a file encoding marker, not hidden text.
            if (str_starts_with($content, "\u{FEFF}")) {
                $content = substr($content, 3);
            }
        }

        if (@preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = max(0, $matches[0][1] - 20);
        $snippet = '[invisible unicode] '.mb_substr(preg_replace($pattern, '?', mb_strcut($content, $offset, 120)) ?? '', 0, 120);

        return new ScannerHit($this->id(), $this->severity, $snippet);
    }
}
