<?php

namespace App\Domain\Signal\Enums;

/**
 * Risk tier of an agent-produced fix, assigned by SentryFixTierClassifier.
 *
 * Gates how autonomously the fix may be merged/deployed. In the current
 * Sentry Watchdog sprint (T4-mode) the tier is a label only — nothing is
 * auto-merged — but the classifier is built and tested now so a later
 * sprint can switch on T1 autonomy without re-deriving the rules.
 */
enum FixTier: string
{
    case T1 = 't1';
    case T2 = 't2';
    case T3 = 't3';
    case T4 = 't4';

    public function label(): string
    {
        return match ($this) {
            self::T1 => 'T1 — Trivial',
            self::T2 => 'T2 — Low risk',
            self::T3 => 'T3 — Moderate risk',
            self::T4 => 'T4 — High risk / human-only',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::T1 => 'green',
            self::T2 => 'yellow',
            self::T3 => 'orange',
            self::T4 => 'red',
        };
    }

    /**
     * Whether an agent may merge a fix of this tier without a human.
     * Only T1 — and even T1 requires the watchdog to be out of T4-mode.
     */
    public function isAutoMergeable(): bool
    {
        return $this === self::T1;
    }

    public function requiresHumanMerge(): bool
    {
        return ! $this->isAutoMergeable();
    }
}
