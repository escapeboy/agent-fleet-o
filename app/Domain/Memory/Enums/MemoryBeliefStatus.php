<?php

namespace App\Domain\Memory\Enums;

/**
 * Belief lifecycle status (Tenure-inspired).
 *
 * Runs parallel to the existing tier/proposal_status workflow. Where
 * proposal_status tracks human review of agent-proposed memories, belief
 * status tracks the epistemic confidence in the fact itself.
 *
 *   active      → explicitly stated or decided
 *   inferred    → derived without an explicit statement; awaits confirmation
 *   exploratory → being considered but not committed
 *   superseded  → replaced by a newer belief; retained for audit, never injected
 */
enum MemoryBeliefStatus: string
{
    case Active = 'active';
    case Inferred = 'inferred';
    case Exploratory = 'exploratory';
    case Superseded = 'superseded';

    /** Whether a belief in this status may be injected into agent context. */
    public function isInjectable(): bool
    {
        return $this !== self::Superseded;
    }
}
