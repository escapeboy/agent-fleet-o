<?php

namespace App\Domain\VoiceSession\Exceptions;

use RuntimeException;

/**
 * Thrown when a voice session operation fails.
 *
 * Examples: LiveKit server unreachable, invalid room name, token generation failure.
 */
class VoiceSessionException extends RuntimeException
{
    public static function tokenGenerationFailed(string $reason): self
    {
        return new self("Failed to generate LiveKit token: {$reason}");
    }

    public static function missingConfiguration(): self
    {
        return new self('LiveKit is not configured. Connect a LiveKit integration in the Integrations page, or set LIVEKIT_API_KEY and LIVEKIT_API_SECRET in your environment.');
    }

    public static function noIntegration(): self
    {
        return new self('No LiveKit credentials found. Please connect a LiveKit integration in the Integrations page.');
    }

    public static function sessionNotActive(string $sessionId): self
    {
        return new self("Voice session {$sessionId} is not active.");
    }
}
