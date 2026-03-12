<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentConfigRevision;
use Illuminate\Support\Facades\DB;

class RollbackAgentConfigAction
{
    public function __construct(
        private readonly RecordAgentConfigRevisionAction $recordRevision,
    ) {}

    /**
     * Roll back an agent's configuration to the state captured in a specific revision.
     *
     * Creates a new revision with source='rollback' pointing to the source revision.
     */
    public function execute(
        Agent $agent,
        AgentConfigRevision $revision,
        ?string $userId = null,
    ): Agent {
        // The "target" state is what the config was BEFORE the revision was applied
        $targetConfig = $revision->before_config;

        return DB::transaction(function () use ($agent, $revision, $targetConfig, $userId) {
            // Record the rollback as a new revision (before applying the change)
            $this->recordRevision->execute(
                agent: $agent,
                newData: $targetConfig,
                source: 'rollback',
                userId: $userId,
                rolledBackFromRevisionId: $revision->id,
                notes: "Rolled back to state before revision {$revision->id}",
            );

            $agent->update($targetConfig);
            $agent->refresh();

            return $agent;
        });
    }
}
