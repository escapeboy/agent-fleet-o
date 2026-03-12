<?php

namespace App\Domain\Skill\Events;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;

/**
 * Fired after a skill execution completes.
 */
class SkillExecuted
{
    public function __construct(
        public readonly Skill $skill,
        public readonly SkillExecution $execution,
        public readonly bool $succeeded,
    ) {}
}
