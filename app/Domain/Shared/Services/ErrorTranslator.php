<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Mcp\ErrorCode;
use Illuminate\Support\Facades\Cache;

/**
 * Translates technical exception strings into customer-readable diagnoses
 * with recommended recovery actions.
 *
 * Two-layer strategy:
 *   1. Dictionary regex match (fast, deterministic, free) — see config/error-translations.php
 *   2. Fallback to the 'unknown' bucket from the dictionary
 *
 * Stays dictionary-only by design: an LLM fallback was considered but rejected
 * for the P0 sprint to avoid burning credits on rare unknown patterns. Unknown
 * errors are logged via the caller for later dictionary expansion.
 *
 * Result objects are cached per technical message + locale for 5 minutes so
 * repeated diagnoses on the same failed experiment don't repeat regex work.
 */
final class ErrorTranslator
{
    /** @var array<string, mixed>|null */
    private ?array $dictionary = null;

    private const FALLBACK_CODE = 'unknown';

    private const ALLOWED_LOCALES = ['en', 'bg'];

    private const ALLOWED_ACTION_KINDS = ['route', 'tool', 'assistant'];

    private const ALLOWED_TIERS = ['safe', 'config', 'destructive'];

    private const CACHE_TTL_SECONDS = 300;

    /**
     * Translate a technical exception/error string for the given locale.
     *
     * @param  array<string, string>  $placeholders   replacements applied to action params + assistant prompt targets;
     *                                                e.g. ['experiment_id' => 'abc-123']
     */
    public function translate(
        string $technicalMessage,
        ?string $locale = null,
        array $placeholders = [],
    ): ErrorTranslation {
        $locale = $this->normalizeLocale($locale);
        $key = $this->cacheKey($technicalMessage, $locale, $placeholders);

        return Cache::remember(
            $key,
            self::CACHE_TTL_SECONDS,
            fn () => $this->translateUncached($technicalMessage, $locale, $placeholders),
        );
    }

    /**
     * Translate without caching. Intended for tests and direct callers
     * that already manage their own cache.
     *
     * @param  array<string, string>  $placeholders
     */
    public function translateUncached(
        string $technicalMessage,
        ?string $locale = null,
        array $placeholders = [],
    ): ErrorTranslation {
        $locale = $this->normalizeLocale($locale);
        $dictionary = $this->loadDictionary();

        [$code, $entry, $matched] = $this->matchDictionary($technicalMessage, $dictionary);

        $mcpCode = $this->resolveMcpCode($entry['mcp_code'] ?? null);
        $message = $this->localizedString($entry['message'] ?? null, $locale, $technicalMessage);
        $actions = $this->buildActions($entry['actions'] ?? [], $locale, $placeholders);

        return new ErrorTranslation(
            code: $code,
            message: $message,
            actions: $actions,
            technicalMessage: $technicalMessage,
            matched: $matched,
            mcpErrorCode: $mcpCode,
            retryable: $entry['retryable'] ?? $mcpCode->isRetryable(),
        );
    }

    /**
     * @param  array<string, mixed>  $dictionary
     * @return array{0: string, 1: array<string, mixed>, 2: bool}
     */
    private function matchDictionary(string $technicalMessage, array $dictionary): array
    {
        foreach ($dictionary as $code => $entry) {
            if ($code === self::FALLBACK_CODE) {
                continue;
            }
            $patterns = $entry['patterns'] ?? [];
            foreach ($patterns as $pattern) {
                if (! is_string($pattern) || $pattern === '') {
                    continue;
                }
                // Suppress malformed-regex notices: a bad config entry should not crash translation.
                if (@preg_match($pattern, $technicalMessage) === 1) {
                    return [$code, $entry, true];
                }
            }
        }

        $fallback = $dictionary[self::FALLBACK_CODE] ?? [];

        return [self::FALLBACK_CODE, $fallback, false];
    }

    /**
     * @param  list<array<string, mixed>>  $rawActions
     * @param  array<string, string>  $placeholders
     * @return list<RecommendedAction>
     */
    private function buildActions(array $rawActions, string $locale, array $placeholders): array
    {
        $built = [];

        foreach ($rawActions as $raw) {
            $kind = $raw['kind'] ?? null;
            if (! in_array($kind, self::ALLOWED_ACTION_KINDS, true)) {
                continue;
            }
            $tier = $raw['tier'] ?? 'safe';
            if (! in_array($tier, self::ALLOWED_TIERS, true)) {
                $tier = 'safe';
            }
            $target = $this->applyPlaceholders((string) ($raw['target'] ?? ''), $placeholders);
            $params = $this->applyPlaceholdersDeep((array) ($raw['params'] ?? []), $placeholders);
            $label = $this->localizedString($raw['label'] ?? null, $locale, '...');
            $icon = isset($raw['icon']) ? (string) $raw['icon'] : null;

            $built[] = new RecommendedAction(
                kind: $kind,
                label: $label,
                target: $target,
                tier: $tier,
                icon: $icon,
                params: $params,
            );
        }

        return $built;
    }

    /** @param  array<string, string>|string|null  $field */
    private function localizedString(mixed $field, string $locale, string $default): string
    {
        if (is_array($field)) {
            return $field[$locale] ?? $field['en'] ?? $default;
        }
        if (is_string($field) && $field !== '') {
            return $field;
        }

        return $default;
    }

    /** @param  array<string, string>  $placeholders */
    private function applyPlaceholders(string $value, array $placeholders): string
    {
        if ($value === '' || $placeholders === []) {
            return $value;
        }
        $replacements = [];
        foreach ($placeholders as $key => $replacement) {
            $replacements['{'.$key.'}'] = (string) $replacement;
        }

        return strtr($value, $replacements);
    }

    /**
     * @param  array<string, string|array<string, string>>  $params
     * @param  array<string, string>  $placeholders
     * @return array<string, string|array<string, string>>
     */
    private function applyPlaceholdersDeep(array $params, array $placeholders): array
    {
        $out = [];
        foreach ($params as $key => $val) {
            if (is_array($val)) {
                /** @var array<string, string> $val */
                $out[$key] = $this->applyPlaceholdersDeep($val, $placeholders);
            } elseif (is_string($val)) {
                $out[$key] = $this->applyPlaceholders($val, $placeholders);
            } else {
                $out[$key] = $val;
            }
        }

        return $out;
    }

    private function resolveMcpCode(?string $name): ErrorCode
    {
        if ($name === null) {
            return ErrorCode::Internal;
        }
        foreach (ErrorCode::cases() as $case) {
            if ($case->value === $name) {
                return $case;
            }
        }

        return ErrorCode::Internal;
    }

    private function normalizeLocale(?string $locale): string
    {
        $candidate = $locale ?? app()->getLocale();
        $candidate = strtolower(substr($candidate, 0, 2));

        return in_array($candidate, self::ALLOWED_LOCALES, true) ? $candidate : 'en';
    }

    /** @param  array<string, string>  $placeholders */
    private function cacheKey(string $msg, string $locale, array $placeholders): string
    {
        ksort($placeholders);

        return 'error_translator:'.sha1($msg.'|'.$locale.'|'.json_encode($placeholders));
    }

    /** @return array<string, mixed> */
    private function loadDictionary(): array
    {
        if ($this->dictionary === null) {
            /** @var array<string, mixed> $dict */
            $dict = config('error-translations', []);
            $this->dictionary = $dict;
        }

        return $this->dictionary;
    }
}
