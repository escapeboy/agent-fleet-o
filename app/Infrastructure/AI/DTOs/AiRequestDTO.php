<?php

namespace App\Infrastructure\AI\DTOs;

use Prism\Prism\Schema\ObjectSchema;

final readonly class AiRequestDTO
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $systemPrompt,
        public string $userPrompt,
        public int $maxTokens = 4096,
        public ?ObjectSchema $outputSchema = null,
        public ?string $userId = null,
        public ?string $teamId = null,
        public ?string $experimentId = null,
        public ?string $experimentStageId = null,
        public ?string $agentId = null,
        public ?string $purpose = null,
        public ?string $idempotencyKey = null,
        public float $temperature = 0.7,
    ) {}

    public function isStructured(): bool
    {
        return $this->outputSchema !== null;
    }

    public function generateIdempotencyKey(): string
    {
        return $this->idempotencyKey ?? hash('xxh128', implode('|', [
            $this->provider,
            $this->model,
            $this->systemPrompt,
            $this->userPrompt,
            $this->experimentId ?? '',
            $this->purpose ?? '',
        ]));
    }
}
