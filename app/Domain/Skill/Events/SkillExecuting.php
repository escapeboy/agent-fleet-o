<?php

namespace App\Domain\Skill\Events;

use App\Domain\Skill\Models\Skill;

/**
 * Fired before a skill is executed.
 */
class SkillExecuting
{
    public bool $cancel = false;

    public ?string $cancelReason = null;

    public function __construct(
        public readonly Skill $skill,
        public array $input,
    ) {}

    public function cancel(string $reason = 'Cancelled by plugin'): void
    {
        $this->cancel = true;
        $this->cancelReason = $reason;
    }
}
