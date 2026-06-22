<?php

namespace App\Infrastructure\AI\Guardrails;

use App\Domain\Credential\Services\SecretPatternLibrary;
use App\Infrastructure\AI\Guardrails\Contracts\ScannerInterface;
use App\Infrastructure\AI\Guardrails\Scanners\CodeFenceExfilScanner;
use App\Infrastructure\AI\Guardrails\Scanners\InvisibleCharScanner;
use App\Infrastructure\AI\Guardrails\Scanners\JailbreakScanner;
use App\Infrastructure\AI\Guardrails\Scanners\PiiScanner;
use App\Infrastructure\AI\Guardrails\Scanners\ProfanityScanner;
use App\Infrastructure\AI\Guardrails\Scanners\PromptInjectionScanner;
use App\Infrastructure\AI\Guardrails\Scanners\SecretScanner;
use App\Infrastructure\AI\Guardrails\Scanners\UrlScanner;

/**
 * Resolves the set of typed guardrail scanners enabled for a scan direction
 * from config('ai_safety.scanners'). Each entry: enabled, target, severity
 * (+ scanner-specific options). Construction is cheap (pure objects), so the
 * registry rebuilds per call rather than caching mutable state.
 */
class ScannerRegistry
{
    public function __construct(private readonly SecretPatternLibrary $secretLibrary) {}

    /**
     * @return list<ScannerInterface>
     */
    public function enabledFor(string $direction): array
    {
        /** @var array<string, array<string, mixed>> $config */
        $config = config('ai_safety.scanners', []);
        $scanners = [];

        foreach ($config as $key => $settings) {
            if (! ($settings['enabled'] ?? false)) {
                continue;
            }

            $target = $settings['target'] ?? 'both';

            if ($target !== 'both' && $target !== $direction) {
                continue;
            }

            $scanner = $this->make((string) $key, $settings);

            if ($scanner !== null) {
                $scanners[] = $scanner;
            }
        }

        return $scanners;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function make(string $key, array $settings): ?ScannerInterface
    {
        $severity = (string) ($settings['severity'] ?? 'medium');

        return match ($key) {
            'invisible_chars' => new InvisibleCharScanner($severity),
            'secrets' => new SecretScanner($this->secretLibrary, $severity),
            'pii' => new PiiScanner($severity),
            'prompt_injection' => new PromptInjectionScanner($severity),
            'jailbreak' => new JailbreakScanner($severity),
            'url' => new UrlScanner($severity, $this->stringList($settings['allowlist'] ?? [])),
            'profanity' => new ProfanityScanner($severity, $this->stringList($settings['words'] ?? [])),
            'code_exfil' => new CodeFenceExfilScanner($severity, (int) ($settings['min_bytes'] ?? 512)),
            default => null,
        };
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}
