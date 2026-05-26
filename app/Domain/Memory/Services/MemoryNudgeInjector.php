<?php

namespace App\Domain\Memory\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;

/**
 * Produces an in-execution "persist your learnings" nudge for an agent,
 * borrowed from Hermes-agent's closed learning loop.
 *
 * FleetQ already auto-extracts memories after the fact (DistillTeamEventsAction,
 * daily). This complements that by reminding the agent *during* a run to capture
 * durable learnings itself — but only when it has accumulated un-memorialized
 * activity, and only when the team has opted in. Default off; no DB schema change.
 */
class MemoryNudgeInjector
{
    public function nudgeFor(Agent $agent): ?string
    {
        $team = $agent->team;
        if (! $team instanceof Team) {
            return null;
        }

        if (! ($team->settings['memory_nudge_enabled'] ?? false)) {
            return null;
        }

        $threshold = (int) config('memory.nudge.execution_threshold', 5);
        if ($threshold < 1) {
            return null;
        }

        // Executions completed since the agent last recorded a memory (all-time if none).
        $lastMemoryAt = Memory::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->max('created_at');

        $unMemorialized = AgentExecution::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->when($lastMemoryAt !== null, fn ($q) => $q->where('created_at', '>', $lastMemoryAt))
            ->count();

        if ($unMemorialized < $threshold) {
            return null;
        }

        return 'You have completed several tasks without recording any durable learnings. '
            .'If anything from this work is worth keeping for the future — a reusable pattern, '
            .'a non-obvious constraint, or a fix that took real effort — persist it now using the '
            .'memory store tool. Keep each memory to one specific, self-contained fact.';
    }
}
