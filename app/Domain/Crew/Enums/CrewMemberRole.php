<?php

namespace App\Domain\Crew\Enums;

enum CrewMemberRole: string
{
    case Coordinator = 'coordinator';
    case Qa = 'qa';
    case Worker = 'worker';

    public function label(): string
    {
        return match ($this) {
            self::Coordinator => 'Coordinator',
            self::Qa => 'QA',
            self::Worker => 'Worker',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Coordinator => 'indigo',
            self::Qa => 'purple',
            self::Worker => 'sky',
        };
    }
}
