<?php

namespace App\Domain\AgentSession\Actions;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;

class CreateAgentSessionAction
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        string $teamId,
        ?string $agentId = null,
        ?string $experimentId = null,
        ?string $crewExecutionId = null,
        ?string $userId = null,
        array $metadata = [],
    ): AgentSession {
        return AgentSession::create([
            'team_id' => $teamId,
            'agent_id' => $agentId,
            'experiment_id' => $experimentId,
            'crew_execution_id' => $crewExecutionId,
            'user_id' => $userId,
            'status' => AgentSessionStatus::Pending,
            'metadata' => $metadata ?: null,
        ]);
    }
}
