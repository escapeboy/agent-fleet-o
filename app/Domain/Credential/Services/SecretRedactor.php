<?php

namespace App\Domain\Credential\Services;

use App\Domain\Credential\DTOs\RedactionResult;
use RuntimeException;

/**
 * Replaces secrets in text with a placeholder before it is persisted.
 *
 * Layers run in order (vendor patterns, credentialed URIs, DSN passwords, .env
 * assignments, JSON secret fields, bearer headers). Each layer replaces the value
 * only and keeps its label, and skips values that are clearly not secrets
 * (placeholders, variable references, code expressions), so a second pass over
 * already-redacted text changes nothing. There is no entropy layer on purpose.
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '[REDACTED]';

    public const FAILED = '[REDACTION_FAILED]';

    private const ENV_NAME_KEYWORDS = '/PASSWORD|PASSWD|SECRET|TOKEN(?!S)|API_KEY|APIKEY|PRIVATE_KEY|ACCESS_KEY|CREDENTIAL|_KEY$|^APP_KEY$|_PWD$|(?:^|_)AUTH(?:_|$)/';

    private const JSON_NAMES = 'password|passwd|secret|client_secret|api_key|apikey|access_token|refresh_token|token|private_key|authorization';

    private const PLACEHOLDER_WORDS = ['changeme', 'example', 'placeholder', 'secret', 'password', 'xxxxxxxx', 'test', 'dummy', 'redacted', 'null', 'true', 'false', 'none'];

    public function __construct(private readonly SecretPatternLibrary $library) {}

    public function redact(mixed $value): mixed
    {
        $counts = [];

        return $this->walk($value, $counts);
    }

    /**
     * @return array{value: mixed, counts: array<string, int>}
     */
    public function redactWithCounts(mixed $value): array
    {
        $counts = [];
        $redacted = $this->walk($value, $counts);

        return ['value' => $redacted, 'counts' => $counts];
    }

    public function redactString(string $text): RedactionResult
    {
        $counts = [];

        return new RedactionResult($this->redactText($text, $counts), $counts);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function walk(mixed $value, array &$counts): mixed
    {
        if (is_string($value)) {
            return $this->redactText($value, $counts);
        }

        if (is_array($value)) {
            foreach ($value as $key => $leaf) {
                $value[$key] = $this->walk($leaf, $counts);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function redactText(string $text, array &$counts): string
    {
        if ($text === '') {
            return $text;
        }

        $text = $this->vendor($text, $counts);
        $text = $this->credentialedUri($text, $counts);
        $text = $this->connectionString($text, $counts);
        $text = $this->envAssignment($text, $counts);
        $text = $this->jsonSecret($text, $counts);

        return $this->bearer($text, $counts);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function vendor(string $text, array &$counts): string
    {
        $patterns = $this->library->patterns();

        // The full PEM block goes first: the header-only pattern would otherwise eat
        // the BEGIN line and leave the key body behind.
        $block = $patterns['PRIVATE_KEY_BLOCK'];
        unset($patterns['PRIVATE_KEY_BLOCK']);
        $patterns = ['PRIVATE_KEY_BLOCK' => $block] + $patterns;

        foreach ($patterns as $definition) {
            $text = $this->replace($definition['regex'], $text, 'vendor', $counts, static fn (): string => self::PLACEHOLDER);
        }

        return $text;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function credentialedUri(string $text, array &$counts): string
    {
        return $this->replace(
            '#\b([a-z][a-z0-9+.\-]*://[^\s:/@]+:)([^\s@/]+)(@)#i',
            $text,
            'credentialed_uri',
            $counts,
            fn (array $m): ?string => $this->isSecretValue($m[2]) ? $m[1].self::PLACEHOLDER.$m[3] : null,
        );
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function connectionString(string $text, array &$counts): string
    {
        return $this->replace(
            '/\b((?:password|pwd)[ \t]*=[ \t]*)("[^"]*"|\'[^\']*\'|[^;\s"\']+)/i',
            $text,
            'connection_string',
            $counts,
            // An uppercase PWD= is the shell's working-directory variable, not a password.
            fn (array $m): ?string => str_starts_with($m[1], 'PWD') ? null : $this->replaceValue($m[1], $m[2]),
        );
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function envAssignment(string $text, array &$counts): string
    {
        return $this->replace(
            '/^([ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=[ \t]*)(.*?)([ \t\r]*)$/m',
            $text,
            'env_assignment',
            $counts,
            function (array $m): ?string {
                if (! preg_match(self::ENV_NAME_KEYWORDS, $m[2])) {
                    return null;
                }

                $replaced = $this->replaceValue($m[1], $m[3]);

                return $replaced === null ? null : $replaced.$m[4];
            },
        );
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function jsonSecret(string $text, array &$counts): string
    {
        return $this->replace(
            '/("(?:'.self::JSON_NAMES.')"\s*:\s*)"((?:[^"\\\\]|\\\\.)*)"/i',
            $text,
            'json_secret',
            $counts,
            fn (array $m): ?string => $this->isSecretValue($m[2]) ? $m[1].'"'.self::PLACEHOLDER.'"' : null,
        );
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function bearer(string $text, array &$counts): string
    {
        return $this->replace(
            '/\b(bearer\s+|api-key\s*:\s*)([^\s"\',;]+)/i',
            $text,
            'bearer',
            $counts,
            function (array $m): ?string {
                if (! $this->isSecretValue($m[2])) {
                    return null;
                }

                // "bearer authentication" in prose is not a token; a bearer token has a digit.
                if (stripos($m[1], 'bearer') === 0 && (strlen($m[2]) < 16 || ! preg_match('/\d/', $m[2]))) {
                    return null;
                }

                return $m[1].self::PLACEHOLDER;
            },
        );
    }

    /**
     * @param  callable(array<int, string>): ?string  $callback  Returns the replacement, or null to leave the match alone.
     * @param  array<string, int>  $counts
     */
    private function replace(string $regex, string $text, string $layer, array &$counts, callable $callback): string
    {
        $result = preg_replace_callback($regex, function (array $m) use ($callback, $layer, &$counts): string {
            $replacement = $callback($m);

            if ($replacement === null) {
                return $m[0];
            }

            $counts[$layer] = ($counts[$layer] ?? 0) + 1;

            return $replacement;
        }, $text);

        if ($result === null) {
            throw new RuntimeException('Secret redaction regex failed ('.preg_last_error_msg().').');
        }

        return $result;
    }

    /**
     * Replace a possibly quoted value, keeping the quotes; null when it is not a secret.
     */
    private function replaceValue(string $prefix, string $raw): ?string
    {
        $quote = '';
        $value = $raw;

        if (strlen($raw) >= 2 && ($raw[0] === '"' || $raw[0] === "'") && $raw[-1] === $raw[0]) {
            $quote = $raw[0];
            $value = substr($raw, 1, -1);
        }

        if (! $this->isSecretValue($value)) {
            return null;
        }

        return $prefix.$quote.self::PLACEHOLDER.$quote;
    }

    private function isSecretValue(string $value): bool
    {
        $value = trim($value);

        if (strlen($value) < 6) {
            return false;
        }

        if ($value === self::PLACEHOLDER || $value === self::FAILED) {
            return false;
        }

        if ($value[0] === '$' || $value[0] === '%' || preg_match('/^\{\{.*\}\}$/s', $value)) {
            return false;
        }

        if (preg_match('/^(?:[*xX•.#_-])+$/u', $value) || preg_match('/^<.*>$/s', $value)) {
            return false;
        }

        $lower = strtolower($value);

        if (in_array($lower, self::PLACEHOLDER_WORDS, true) || str_starts_with($lower, 'your-') || str_starts_with($lower, 'your_')) {
            return false;
        }

        foreach (['(', '[', 'os.environ', 'process.env', 'getenv', 'config('] as $codeMarker) {
            if (str_contains($value, $codeMarker)) {
                return false;
            }
        }

        return true;
    }
}
