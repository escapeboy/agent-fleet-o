<?php

namespace App\Domain\Shared\DTOs;

/**
 * Result of a plugin health check.
 *
 * Returned by FleetPlugin implementations of HasHealthCheck::check().
 */
readonly class PluginHealth
{
    public function __construct(
        public bool $healthy,

        /** 'ok' | 'warning' | 'error' */
        public string $status,

        /** Human-readable description of the health state */
        public string $message,
    ) {}

    public static function ok(string $message = 'Plugin is healthy'): self
    {
        return new self(healthy: true, status: 'ok', message: $message);
    }

    public static function warning(string $message): self
    {
        return new self(healthy: true, status: 'warning', message: $message);
    }

    public static function error(string $message): self
    {
        return new self(healthy: false, status: 'error', message: $message);
    }
}
