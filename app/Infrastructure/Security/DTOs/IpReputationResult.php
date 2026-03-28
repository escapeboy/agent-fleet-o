<?php

namespace App\Infrastructure\Security\DTOs;

readonly class IpReputationResult
{
    public function __construct(
        public string $ip,
        public int $abuseScore,
        public bool $isTor,
        public bool $isVpn,
        public ?string $countryCode,
        public bool $fromCache,
    ) {}

    public function isHighRisk(int $threshold = 75): bool
    {
        return $this->abuseScore >= $threshold || $this->isTor;
    }
}
