<?php

namespace App\Domain\Website\DTOs;

use App\Domain\Website\Enums\DeploymentStatus;

final class DeploymentResult
{
    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public readonly DeploymentStatus $status,
        public readonly ?string $url,
        public readonly string $logMessage,
        public readonly array $providerMetadata = [],
    ) {}

    public static function success(?string $url, string $logMessage, array $providerMetadata = []): self
    {
        return new self(DeploymentStatus::Deployed, $url, $logMessage, $providerMetadata);
    }

    public static function failure(string $logMessage, array $providerMetadata = []): self
    {
        return new self(DeploymentStatus::Failed, null, $logMessage, $providerMetadata);
    }
}
