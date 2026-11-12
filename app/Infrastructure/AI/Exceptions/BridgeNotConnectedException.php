<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

/**
 * Thrown when a run needs FleetQ Bridge (bridge_llm / bridge_agent) but the
 * team has no active bridge connection. This is a user setup state, not a
 * defect, so it is excluded from error reporting.
 */
class BridgeNotConnectedException extends RuntimeException
{
    public const DOWNLOAD_URL = 'https://github.com/escapeboy/fleetq-bridge';

    public static function notConnected(): self
    {
        return new self(
            'FleetQ Bridge is not connected, so agents using a local model cannot run. '
            .'Download and start the bridge daemon ('.self::DOWNLOAD_URL.'), then check its status in Team Settings.',
        );
    }

    public static function reconnectTimeout(int $seconds): self
    {
        return new self(
            "FleetQ Bridge disconnected and did not reconnect within {$seconds}s. "
            .'Make sure the bridge daemon ('.self::DOWNLOAD_URL.') is running, then check its status in Team Settings.',
        );
    }

    /**
     * Whether the given provider is served through FleetQ Bridge.
     */
    public static function usesBridge(string $provider): bool
    {
        if ($provider === '') {
            return false;
        }

        if (in_array($provider, ['bridge_llm', 'bridge_agent'], true)) {
            return true;
        }

        return (bool) config("llm_providers.{$provider}.bridge", false);
    }
}
