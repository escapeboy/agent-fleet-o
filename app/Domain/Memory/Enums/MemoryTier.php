<?php

namespace App\Domain\Memory\Enums;

/**
 * Represents the curation tier of a memory entry.
 *
 * Inspired by BroodMind's canonical memory file hierarchy:
 * facts.md, decisions.md, failures.md — each serving a distinct purpose.
 *
 * Tier hierarchy (lowest → highest trust):
 *   working   → auto-written by agents, may be noisy, default for all new memories
 *   proposed  → agent-extracted, awaiting human or system review
 *   canonical → human-approved or system-crystallised, higher retrieval score boost
 *   facts     → stable factual assertions (sub-tier of canonical)
 *   decisions → architectural / strategic decisions with rationale
 *   failures  → lessons learned from failed experiments (see ExtractFailureLessonAction)
 *   successes → effective patterns from completed experiments (see ExtractSuccessPatternAction)
 */
enum MemoryTier: string
{
    case Working = 'working';
    case Proposed = 'proposed';
    case Canonical = 'canonical';
    case Facts = 'facts';
    case Decisions = 'decisions';
    case Failures = 'failures';
    case Successes = 'successes';

    /** Whether this tier is considered curated (canonical or above). */
    public function isCurated(): bool
    {
        return match ($this) {
            self::Canonical, self::Facts, self::Decisions, self::Failures, self::Successes => true,
            default => false,
        };
    }

    /** Score boost applied during retrieval for curated tiers. */
    public function retrievalBoost(): float
    {
        return $this->isCurated() ? 0.10 : 0.0;
    }
}
