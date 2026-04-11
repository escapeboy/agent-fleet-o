<?php

namespace App\Domain\Assistant\Artifacts\Support;

/**
 * Central place for sanitizing LLM-sourced strings before they reach the
 * database or the renderer. Every string that could end up in HTML goes
 * through clean() with an appropriate cap.
 *
 * This is the ONLY tool for turning untrusted LLM output into a string
 * we're willing to store. Do not bypass it.
 */
final class StringSanitizer
{
    /**
     * Strip tags, trim, cap to the given length. Returns null for empty input.
     */
    public static function clean(mixed $value, int $cap = 200): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $str = (string) $value;
        $str = strip_tags($str);
        $str = trim($str);

        if ($str === '') {
            return null;
        }

        // Remove control characters except tab + newline (mb-safe).
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str) ?? '';

        if ($cap > 0 && mb_strlen($str) > $cap) {
            $str = mb_substr($str, 0, $cap);
        }

        return $str === '' ? null : $str;
    }

    /**
     * Same as clean() but guaranteed to return a string (empty if input was bad).
     * Useful when the caller needs a non-null string.
     */
    public static function cleanOrEmpty(mixed $value, int $cap = 200): string
    {
        return self::clean($value, $cap) ?? '';
    }

    /**
     * Normalize a field name to snake_case ASCII: strip anything non-alphanumeric
     * or underscore, cap to 40 chars. Used for field identifiers, CSS ids, etc.
     */
    public static function slugify(mixed $value, int $cap = 40): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $slug = strtolower((string) $value);
        $slug = preg_replace('/[^a-z0-9_]/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        if ($slug === '') {
            return null;
        }

        return mb_substr($slug, 0, $cap);
    }

    /**
     * Clamp a numeric value to [min, max]. Returns null if input is not numeric.
     */
    public static function clampNumber(mixed $value, ?float $min = null, ?float $max = null): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $num = (float) $value;

        if ($min !== null && $num < $min) {
            $num = $min;
        }
        if ($max !== null && $num > $max) {
            $num = $max;
        }

        return $num;
    }
}
