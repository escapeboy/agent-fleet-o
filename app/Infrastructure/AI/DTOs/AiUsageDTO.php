<?php

namespace App\Infrastructure\AI\DTOs;

final readonly class AiUsageDTO
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $costCredits,
        public int $cachedInputTokens = 0,
        public ?string $cacheStrategy = null,
        public int $cacheWriteInputTokens = 0,
        /** Cost from the accurate formula; equals costCredits when llm_pricing.accurate_usage_cost is on. */
        public ?int $accurateCostCredits = null,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
