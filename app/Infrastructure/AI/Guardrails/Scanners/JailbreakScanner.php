<?php

namespace App\Infrastructure\AI\Guardrails\Scanners;

use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;

/**
 * Known jailbreak markers (DAN-family, "no restrictions" framing). Complements
 * the ai_safety.rules 'jailbreak-dan' contains rule with a broader phrase set.
 */
class JailbreakScanner implements ScannerInterface
{
    private const PATTERNS = [
        '/\bdo\s+anything\s+now\b/i',
        '/\bjailbreak\b/i',
        '/\b(you\s+are|act\s+as)\s+an?\s+(unfiltered|uncensored|unrestricted)\b/i',
        '/\bwithout\s+(any\s+)?(restrictions|rules|filters|guidelines)\b/i',
        '/\bno\s+(ethical|moral|content)\s+(guidelines|filters|restrictions)\b/i',
    ];

    public function __construct(private readonly string $severity = 'high') {}

    public function id(): string
    {
        return 'jailbreak';
    }

    public function scan(string $content, string $direction): ?ScannerHit
    {
        if ($content === '') {
            return null;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $offset = max(0, $matches[0][1] - 20);

                return new ScannerHit($this->id(), $this->severity, mb_strcut($content, $offset, 120));
            }
        }

        return null;
    }
}
