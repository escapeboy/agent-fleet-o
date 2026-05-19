<?php

namespace App\Domain\Memory\Enums;

/**
 * Structured belief taxonomy (Tenure-inspired).
 *
 * Sits alongside the existing {@see MemoryCategory} taxonomy. Where category
 * describes the memory hall a record lives in, belief type describes the
 * epistemic shape of the fact: is it a preference, a committed decision, a
 * named thing, a link between things, or an unresolved question.
 *
 *   preference     → how the user/agent works and communicates
 *   decision       → a commitment future runs must respect
 *   entity         → a named thing in the user's world
 *   relation       → a connection between entities
 *   open_question  → something actively being worked through, not yet decided
 */
enum MemoryBeliefType: string
{
    case Preference = 'preference';
    case Decision = 'decision';
    case Entity = 'entity';
    case Relation = 'relation';
    case OpenQuestion = 'open_question';

    /** Whether this belief type accepts a {@see MemoryPreferenceSubtype}. */
    public function acceptsPreferenceSubtype(): bool
    {
        return $this === self::Preference;
    }
}
