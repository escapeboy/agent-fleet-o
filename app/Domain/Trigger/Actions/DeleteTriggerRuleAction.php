<?php

namespace App\Domain\Trigger\Actions;

use App\Domain\Trigger\Models\TriggerRule;

class DeleteTriggerRuleAction
{
    public function execute(TriggerRule $rule): void
    {
        $rule->delete();
    }
}
