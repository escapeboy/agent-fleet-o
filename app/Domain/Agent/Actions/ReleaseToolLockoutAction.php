<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\AgentToolLockout;

/**
 * Release an active reviewer-lockout — the 30-second undo once a locked
 * resource has been reviewed and cleared.
 */
class ReleaseToolLockoutAction
{
    public function execute(AgentToolLockout $lockout): AgentToolLockout
    {
        if ($lockout->released_at === null) {
            $lockout->update(['released_at' => now()]);
        }

        return $lockout->fresh();
    }
}
