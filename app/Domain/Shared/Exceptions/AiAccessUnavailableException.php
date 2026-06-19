<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Thrown when a team has no usable path to run AI: no BYOK key, no platform
 * fallback entitlement, and no local/bridge agent — and it is not an internal
 * sub-program. Surfaced early (e.g. on experiment creation) so the team gets an
 * actionable message instead of a mid-run failure.
 */
class AiAccessUnavailableException extends RuntimeException
{
    public static function forTeam(): self
    {
        return new self(
            'Your plan does not include platform AI keys. '
            .'Please add your own API key in Team Settings, or upgrade your plan.',
        );
    }
}
