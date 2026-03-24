<?php

namespace App\Domain\Integration\Exceptions;

use RuntimeException;

/**
 * Thrown when an OAuth2 callback grants fewer scopes than required.
 */
class OAuthScopeMissingException extends RuntimeException
{
    /** @param string[] $missingScopes */
    public function __construct(
        public readonly string $driver,
        public readonly array $missingScopes,
    ) {
        parent::__construct(
            "OAuth2 authorization for '{$driver}' is missing required scopes: ".implode(', ', $missingScopes).
            '. Please re-authorize and grant all requested permissions.',
        );
    }
}
