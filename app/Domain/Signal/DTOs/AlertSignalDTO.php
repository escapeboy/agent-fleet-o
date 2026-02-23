<?php

namespace App\Domain\Signal\DTOs;

readonly class AlertSignalDTO
{
    public function __construct(
        public string $platform,
        public string $alertId,
        public string $title,
        public string $severity,
        public string $status,
        public string $url,
        public ?string $service,
        public ?string $environment,
        public array $rawPayload,
    ) {}
}
