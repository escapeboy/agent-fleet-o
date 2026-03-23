<?php

namespace App\Domain\Agent\Jobs;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Executes a scheduled agent heartbeat.
 *
 * Runs the agent with the heartbeat prompt as input, using the team owner
 * as the acting user (since heartbeats are system-initiated).
 */
class ExecuteAgentHeartbeatJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $agentId,
        public readonly string $teamId,
        public readonly string $prompt,
    ) {
        $this->onQueue('experiments');
    }

    public function uniqueId(): string
    {
        return "agent:heartbeat:{$this->agentId}";
    }

    public function handle(ExecuteAgentAction $action): void
    {
        $agent = Agent::withoutGlobalScopes()
            ->where('id', $this->agentId)
            ->where('team_id', $this->teamId)
            ->first();

        if (! $agent) {
            Log::warning('ExecuteAgentHeartbeatJob: agent not found', ['agent_id' => $this->agentId]);

            return;
        }

        if (! $agent->status->isActive()) {
            Log::info('ExecuteAgentHeartbeatJob: agent inactive, skipping', [
                'agent_id' => $this->agentId,
                'status' => $agent->status->value,
            ]);

            return;
        }

        // Resolve the team owner as the acting user for system-initiated heartbeats
        $userId = $agent->team?->owner_id;
        if (! $userId) {
            Log::warning('ExecuteAgentHeartbeatJob: team has no owner, skipping', ['agent_id' => $this->agentId]);

            return;
        }

        try {
            $result = $action->execute(
                agent: $agent,
                input: ['task' => $this->prompt, '_source' => 'heartbeat'],
                teamId: $this->teamId,
                userId: $userId,
            );

            Log::info('ExecuteAgentHeartbeatJob: heartbeat completed', [
                'agent_id' => $this->agentId,
                'execution_id' => $result['execution']?->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ExecuteAgentHeartbeatJob: heartbeat failed', [
                'agent_id' => $this->agentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
