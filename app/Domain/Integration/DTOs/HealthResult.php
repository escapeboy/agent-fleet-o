<?php

namespace App\Domain\Integration\DTOs;

use Carbon\Carbon;

readonly class HealthResult
{
    public function __construct(
        public bool $healthy,
        public ?string $message = null,
        public ?int $latencyMs = null,
        public ?Carbon $checkedAt = null,
    ) {}

    public static function ok(?int $latencyMs = null): self
    {
        return new self(
            healthy: true,
            latencyMs: $latencyMs,
            checkedAt: now(),
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
