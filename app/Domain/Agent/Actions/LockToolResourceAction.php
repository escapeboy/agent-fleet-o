<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\ToolLockoutMatchMode;
use App\Domain\Agent\Models\AgentToolLockout;

/**
 * Lock a tool resource for review (Squad reviewer-lockout). The original agent
 * can no longer touch the matching path/command/tool until released — a human
 * or a different agent must take over.
 */
class LockToolResourceAction
{
    public function execute(
        string $teamId,
        string $resource,
        ?string $agentId = null,
        ToolLockoutMatchMode $matchMode = ToolLockoutMatchMode::Equals,
        ?string $reason = null,
        ?string $lockedBy = null,
    ): AgentToolLockout {
        return AgentToolLockout::create([
            'team_id' => $teamId,
            'agent_id' => $agentId,
            'resource' => $resource,
            'match_mode' => $matchMode,
            'reason' => $reason,
            'locked_by' => $lockedBy,
        ]);
    }
}
