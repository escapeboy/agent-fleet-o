<?php

namespace App\Infrastructure\AI\DTOs;

/**
 * Billable token counts with no overlap between fields: every token is in
 * exactly one bucket and is priced at that bucket's rate.
 */
final readonly class UsageBreakdown
{
    public function __construct(
        public int $uncachedInputTokens,
        public int $cacheReadInputTokens,
        public int $cacheWriteInputTokens,
        public int $outputTokens,
    ) {}
}
