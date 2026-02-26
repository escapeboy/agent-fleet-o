<?php

namespace App\Domain\Integration\DTOs;

readonly class TriggerDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        /** @var array<string, mixed> */
        public array $outputSchema = [],
    ) {}
}
