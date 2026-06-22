<?php

namespace App\Infrastructure\AI\Guardrails\Scanners;

use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\DTOs\ScannerHit;

/**
 * Curated prompt-injection phrase library, complementing the operator-editable
 * ai_safety.rules pack. Focuses on instruction-override and system-prompt
 * exfiltration phrasings.
 */
class PromptInjectionScanner implements ScannerInterface
{
    private const PATTERNS = [
        '/\bdisregard\s+(all\s+)?(the\s+)?(previous|prior|above|earlier)\b/i',
        '/\b(reveal|print|show|repeat|output)\s+(your|the)\s+(system\s+prompt|initial\s+instructions|instructions)\b/i',
        '/\bignore\s+(everything|all)\s+(above|before)\b/i',
        '/\bbegin\s+your\s+(reply|response|answer)\s+with\b/i',
        '/\b(new|updated)\s+(instructions|system\s+prompt)\s*:/i',
    ];

    public function __construct(private readonly string $severity = 'high') {}

    public function id(): string
    {
        return 'prompt_injection';
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
