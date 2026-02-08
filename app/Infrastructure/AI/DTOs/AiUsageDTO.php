<?php

namespace App\Infrastructure\AI\DTOs;

final readonly class AiUsageDTO
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $costCredits,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
