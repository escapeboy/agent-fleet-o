<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentRuntimeState;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Support\Facades\DB;

class AgentRuntimeStateService
{
    /**
     * Get or create the runtime state for an agent.
     */
    public function get(Agent $agent): AgentRuntimeState
    {
        return AgentRuntimeState::withoutGlobalScopes()
            ->firstOrCreate(
                ['agent_id' => $agent->id],
                ['team_id' => $agent->team_id],
            );
    }

    /**
     * Patch arbitrary fields on the runtime state.
     *
     * @param  array<string, mixed>  $patch
     */
    public function update(Agent $agent, array $patch): AgentRuntimeState
    {
        $state = $this->get($agent);
        $state->update($patch);

        return $state->refresh();
    }

    /**
     * Clear the current session_id (forces a new session on next execution).
     */
    public function resetSession(Agent $agent): void
    {
        AgentRuntimeState::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->update(['session_id' => null]);
    }

    /**
     * Accumulate token/cost totals from a completed AI call.
     */
    public function recordExecution(Agent $agent, AiUsageDTO $usage, ?string $errorMessage = null): void
    {
        DB::transaction(function () use ($agent, $usage, $errorMessage) {
            $state = AgentRuntimeState::withoutGlobalScopes()
                ->lockForUpdate()
                ->where('agent_id', $agent->id)
                ->first();

            if (! $state) {
                return;
            }

            $state->increment('total_executions');
            $state->increment('total_input_tokens', $usage->promptTokens);
            $state->increment('total_output_tokens', $usage->completionTokens);
            $state->increment('total_cost_credits', $usage->costCredits);
            $state->update([
                'last_active_at' => now(),
                'last_error' => $errorMessage,
            ]);
        });
    }
}
