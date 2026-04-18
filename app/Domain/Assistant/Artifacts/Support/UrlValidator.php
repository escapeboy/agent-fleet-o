<?php

namespace App\Domain\Assistant\Artifacts\Support;

/**
 * Strict URL whitelist for link_list items + any artifact prop that could
 * produce a clickable target in the rendered UI.
 *
 * Rejects:
 *  - javascript:, data:, vbscript:, file:, chrome:, blob:, ftp: (anything not http/https)
 *  - host with userinfo ("@")
 *  - host with # or newline/control chars
 *  - IDN homographs (non-ASCII host without punycode)
 *  - URLs longer than 2048 chars
 *  - relative paths with .. / null bytes / CR / LF
 *
 * Accepts:
 *  - absolute http:// or https:// URLs to any host (callers may narrow further)
 *  - relative paths starting with "/" that are safe (no traversal)
 */
final class UrlValidator
{
    private const MAX_LENGTH = 2048;

    private const ALLOWED_SCHEMES = ['http', 'https'];

    public static function isSafe(mixed $url): bool
    {
        if (! is_string($url)) {
            return false;
        }

        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            return false;
        }

        // Reject anything with control characters anywhere.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        // Relative path: must start with exactly one "/" (not "//" which is
        // protocol-relative and could be hijacked to http://attacker/).
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return self::isSafeRelativePath($url);
        }

        // Absolute URL: parse and validate.
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        $host = $parts['host'];

        // Userinfo injection: parse_url strips it into "user", reject if present.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // Homograph / non-ASCII host (callers must punycode-encode upfront).
        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            return false;
        }

        // Very naive IDN check: reject anything with "xn--" unless the user
        // typed it that way (which is fine — we just pass it through).
        // Reject anything with an embedded space.
        if (preg_match('/\s/', $host) === 1) {
            return false;
        }

        return true;
    }

    private static function isSafeRelativePath(string $path): bool
    {
        // Reject directory traversal + embedded newlines + backslash.
        if (str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }

        // Reject protocol-relative sneakiness like "/\attacker.com".
        if (str_starts_with($path, '/\\')) {
            return false;
        }

        return true;
    }

    /**
     * Return the input unchanged if safe, null otherwise. Convenience for
     * "sanitize or drop" flows where null means "this item should be skipped".
     */
    public static function normalize(mixed $url): ?string
    {
        return self::isSafe($url) ? trim((string) $url) : null;
    }
}
