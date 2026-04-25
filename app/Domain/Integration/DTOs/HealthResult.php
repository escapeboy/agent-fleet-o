<?php

namespace App\Domain\Integration\DTOs;

use Carbon\Carbon;

readonly class HealthResult
{
    /**
     * @param  array<string, mixed>|null  $identity  Optional identity payload describing the connected account.
     *                                               Recommended shape: ['label' => '@handle', 'identifier' => '12345',
     *                                               'url' => 'https://...', 'metadata' => [...]]
     */
    public function __construct(
        public bool $healthy,
        public ?string $message = null,
        public ?int $latencyMs = null,
        public ?Carbon $checkedAt = null,
        public ?array $identity = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $identity
     */
    public static function ok(?int $latencyMs = null, ?string $message = null, ?array $identity = null): self
    {
        return new self(
            healthy: true,
            message: $message,
            latencyMs: $latencyMs,
            checkedAt: now(),
            identity: $identity,
        );
    }

    public static function fail(string $message): self
    {
        return new self(
            healthy: false,
            message: $message,
            checkedAt: now(),
        );
    }
}
