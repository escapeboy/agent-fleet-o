<?php

namespace App\Domain\Memory\Enums;

/**
 * Optional subtype for {@see MemoryBeliefType::Preference} beliefs.
 *
 *   expertise → depth calibration: how much the agent should explain
 *   style     → communication patterns: tone, format, voice
 */
enum MemoryPreferenceSubtype: string
{
    case Expertise = 'expertise';
    case Style = 'style';
}
