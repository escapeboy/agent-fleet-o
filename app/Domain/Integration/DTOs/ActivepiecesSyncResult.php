<?php

namespace App\Domain\Integration\DTOs;

/**
 * Result of a single Activepieces tool-sync operation.
 */
readonly class ActivepiecesSyncResult
{
    public function __construct(
        /** Number of Tool records created or updated. */
        public int $upserted,
        /** Number of previously-synced Tool records deactivated (piece no longer present). */
        public int $deactivated,
        /** Human-readable status message. */
        public string $message,
    ) {}

    public static function empty(): self
    {
        return new self(upserted: 0, deactivated: 0, message: 'No pieces found.');
    }
}
