<?php

namespace FleetQ\BorunaAudit\DTOs;

readonly class VerificationResult
{
    public function __construct(
        public bool $passed,
        public \DateTimeImmutable $checkedAt,
        public ?string $errorMessage,
        public ?string $bundlePath,
    ) {}

    public static function pass(string $bundlePath): self
    {
        return new self(
            passed: true,
            checkedAt: new \DateTimeImmutable,
            errorMessage: null,
            bundlePath: $bundlePath,
        );
    }

    public static function fail(string $errorMessage, ?string $bundlePath = null): self
    {
        return new self(
            passed: false,
            checkedAt: new \DateTimeImmutable,
            errorMessage: $errorMessage,
            bundlePath: $bundlePath,
        );
    }
}
