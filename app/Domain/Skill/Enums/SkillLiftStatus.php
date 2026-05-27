<?php

namespace App\Domain\Skill\Enums;

enum SkillLiftStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
