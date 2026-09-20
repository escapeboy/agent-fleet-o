<?php

namespace App\Domain\Decision\Services;

/**
 * Strips API keys out of anything that might be rendered, logged or shipped to
 * Sentry. Every driver funnels its error text through this before it reaches an
 * exception message, so a key can never ride out on a stack trace.
 */
final class CredentialRedactor
{
    public const PLACEHOLDER = '[REDACTED]';

    /**
     * @param  list<string|null>  $secrets
     */
    public static function scrub(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $text = str_replace($secret, self::PLACEHOLDER, $text);
            }
        }

        // Catch-all for bearer tokens that arrived from somewhere other than our
        // own config (a proxy echoing the request, an upstream error body).
        return (string) preg_replace(
            '/(?i)\b(authorization|bearer|api[-_]?key)\b\s*[:=]?\s*\S+/',
            '$1 '.self::PLACEHOLDER,
            $text,
        );
    }
}
