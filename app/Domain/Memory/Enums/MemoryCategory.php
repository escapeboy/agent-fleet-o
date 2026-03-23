<?php

namespace App\Domain\Memory\Enums;

enum MemoryCategory: string
{
    case Preference = 'preference'; // user/team style choices, format preferences
    case Knowledge  = 'knowledge';  // facts, domain knowledge, learned information
    case Context    = 'context';    // situational context, recent events, current state
    case Behavior   = 'behavior';   // working patterns, process preferences, habits
    case Goal       = 'goal';       // objectives, targets, desired outcomes
}
