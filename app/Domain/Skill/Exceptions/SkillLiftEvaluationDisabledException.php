<?php

namespace App\Domain\Skill\Exceptions;

use RuntimeException;

class SkillLiftEvaluationDisabledException extends RuntimeException
{
    public static function forTeam(string $teamId): self
    {
        return new self("Skill lift evaluation is disabled for team {$teamId}. Enable team.settings['skill_lift_eval_enabled'].");
    }
}
