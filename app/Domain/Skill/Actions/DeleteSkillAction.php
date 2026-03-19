<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\Skill;

class DeleteSkillAction
{
    public function execute(Skill $skill): void
    {
        if ($skill->executions()->whereIn('status', ['running', 'pending'])->exists()) {
            throw new \RuntimeException('Cannot delete a skill with active executions.');
        }

        $skill->delete();
    }
}
