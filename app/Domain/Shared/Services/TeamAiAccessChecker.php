<?php

namespace App\Domain\Shared\Services;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Exceptions\AiAccessUnavailableException;
use App\Domain\Shared\Models\Team;

/**
 * Determines whether a team has any usable path to run AI work.
 *
 * Policy: teams are BYOK by default; internal sub-programs and enterprise-tier
 * teams (platform_llm_fallback) are the exceptions. Conservative by design —
 * returns true whenever ANY path exists, so it never blocks a team that could
 * actually run (BYOK, sub-program, local agents, or a connected bridge).
 */
class TeamAiAccessChecker
{
    public function canUseAi(Team $team): bool
    {
        // Internal sub-program (e.g. finance) — funded via sub_program_api_keys.
        if (($team->sub_program_slug ?? 'cloud') !== 'cloud') {
            return true;
        }

        // Plan grants platform AI keys (community edition always returns true here).
        if ($team->hasFeature('platform_llm_fallback')) {
            return true;
        }

        // Team brought its own provider key.
        if ($team->providerCredentials()->where('is_active', true)->exists()) {
            return true;
        }

        // Local CLI agents are enabled at the deployment level.
        if ((bool) config('local_agents.enabled')) {
            return true;
        }

        // A bridge daemon is connected, exposing the team's local agents/LLMs.
        if (BridgeConnection::withoutGlobalScopes()->where('team_id', $team->id)->active()->exists()) {
            return true;
        }

        return false;
    }

    public function assertCanUseAi(Team $team): void
    {
        if (! $this->canUseAi($team)) {
            throw AiAccessUnavailableException::forTeam();
        }
    }
}
