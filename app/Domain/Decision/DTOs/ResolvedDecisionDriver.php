<?php

namespace App\Domain\Decision\DTOs;

use App\Domain\Decision\Contracts\DecisionModel;

/**
 * A decision driver plus the answer to "whose key is paying for this call".
 *
 * `source` is returned rather than left for the caller to re-derive: billing
 * branches on it, and a caller that has to work out who paid after the fact
 * will eventually get it wrong.
 */
final readonly class ResolvedDecisionDriver
{
    public const SOURCE_TEAM = 'team';

    public const SOURCE_PLATFORM = 'platform';

    public function __construct(
        public DecisionModel $driver,
        public string $name,
        public string $source,
        public int $creditsPerCall,
    ) {}

    /**
     * Platform-key calls are billed; a team on its own key already pays the
     * vendor directly and must not be charged twice.
     */
    public function isBillable(): bool
    {
        return $this->source === self::SOURCE_PLATFORM && $this->creditsPerCall > 0;
    }
}
