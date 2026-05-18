<?php

namespace App\Domain\Experiment\Enums;

/**
 * Who is responsible for a unified-timeline entry.
 * Kanwas-inspired sprint — human + agent on one shared timeline.
 */
enum TimelineActor: string
{
    case Human = 'human';
    case Agent = 'agent';
    case System = 'system';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
