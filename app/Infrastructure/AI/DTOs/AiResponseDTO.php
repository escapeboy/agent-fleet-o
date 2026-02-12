<?php

namespace App\Infrastructure\AI\DTOs;

final readonly class AiResponseDTO
{
    public function __construct(
        public string $content,
        public ?array $parsedOutput,
        public AiUsageDTO $usage,
        public string $provider,
        public string $model,
        public int $latencyMs,
        public bool $schemaValid = true,
        public bool $cached = false,
        public ?array $toolResults = null,
        public ?array $steps = null,
        public int $toolCallsCount = 0,
        public int $stepsCount = 0,
    ) {}
}
