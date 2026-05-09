<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

final readonly class LlmTriageVerdictDTO
{
    public function __construct(
        public float $score,
        public string $recommendation,   // review_now | review_soon | low_priority
        public string $reason,
        public bool $fromCache = false,
    ) {}
}
