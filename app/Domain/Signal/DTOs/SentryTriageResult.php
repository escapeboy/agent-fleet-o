<?php

namespace App\Domain\Signal\DTOs;

use App\Domain\Signal\Enums\FixTier;
use App\Domain\Signal\Enums\SentryTriageOutcome;

/**
 * Result of triaging one Sentry issue. Aggregated by RunSentryWatchdogJob to
 * build the batch digest.
 */
final readonly class SentryTriageResult
{
    /**
     * @param  list<string>  $suspectFiles
     */
    public function __construct(
        public string $signalId,
        public SentryTriageOutcome $outcome,
        public ?FixTier $tier = null,
        public ?string $rootCause = null,
        public float $confidence = 0.0,
        public bool $isCritical = false,
        public ?string $summary = null,
        public ?string $experimentId = null,
        public array $suspectFiles = [],
    ) {}

    public static function skipped(string $signalId): self
    {
        return new self($signalId, SentryTriageOutcome::Skipped);
    }

    public static function failed(string $signalId, string $reason): self
    {
        return new self($signalId, SentryTriageOutcome::Failed, summary: $reason);
    }

    public function wasDelegated(): bool
    {
        return $this->outcome === SentryTriageOutcome::Delegated;
    }
}
