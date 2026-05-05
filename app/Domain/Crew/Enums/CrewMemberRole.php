<?php

namespace App\Domain\Crew\Enums;

enum CrewMemberRole: string
{
    case Coordinator = 'coordinator';
    case Qa = 'qa';
    case Worker = 'worker';
    case ProcessReviewer = 'process_reviewer';
    case OutputReviewer = 'output_reviewer';
    case Judge = 'judge';

    public function label(): string
    {
        return match ($this) {
            self::Coordinator => 'Coordinator',
            self::Qa => 'QA',
            self::Worker => 'Worker',
            self::ProcessReviewer => 'Process Reviewer',
            self::OutputReviewer => 'Output Reviewer',
            self::Judge => 'Judge',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Coordinator => 'indigo',
            self::Qa => 'purple',
            self::Worker => 'sky',
            self::ProcessReviewer => 'amber',
            self::OutputReviewer => 'rose',
            self::Judge => 'emerald',
        };
    }
}
