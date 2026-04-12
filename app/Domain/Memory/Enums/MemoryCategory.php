<?php

namespace App\Domain\Memory\Enums;

enum MemoryCategory: string
{
    // Legacy values — kept for backwards compatibility
    case Preference = 'preference'; // user/team style choices, format preferences
    case Knowledge = 'knowledge';  // facts, domain knowledge, learned information
    case Context = 'context';    // situational context, recent events, current state
    case Behavior = 'behavior';   // working patterns, process preferences, habits
    case Goal = 'goal';       // objectives, targets, desired outcomes

    // Canonical hall types (MemPalace-inspired taxonomy)
    case Facts = 'facts';           // decisions made, locked-in choices
    case Events = 'events';         // sessions, milestones, debugging logs
    case Discoveries = 'discoveries'; // breakthroughs, new insights
    case Advice = 'advice';         // recommendations and solutions
}
