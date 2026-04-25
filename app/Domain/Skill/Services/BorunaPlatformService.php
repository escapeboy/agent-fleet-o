<?php

namespace App\Domain\Skill\Services;

use App\Domain\Skill\Actions\RegisterBorunaToolAction;
use App\Domain\Tool\Models\Tool;

/**
 * Boruna availability + activation lookup for UI surfaces.
 *
 * Encapsulates the four states a team can be in vis-à-vis Boruna so callers
 * (the skill creation form, settings pages, etc.) don't reimplement the
 * binary-vs-allowlist-vs-tool checks.
 *
 *   1. binary missing in image       → "operator must rebuild"
 *   2. binary present but not allowed → "operator must update allowlist"
 *   3. binary present, allowed, no tool yet → "self-serve: click Enable"
 *   4. binary present, allowed, tool exists → "ready"
 */
class BorunaPlatformService
{
    /** True if the binary is executable on disk. */
    public function isBinaryAvailable(string $binary = RegisterBorunaToolAction::DEFAULT_BINARY): bool
    {
        return is_executable($binary);
    }

    /** True if the binary is in the mcp_stdio allowlist (or allow-any is on). */
    public function isBinaryAllowed(string $binary = RegisterBorunaToolAction::DEFAULT_BINARY): bool
    {
        if ((bool) config('agent.mcp_stdio_allow_any_binary', false)) {
            return true;
        }

        $allowlist = (array) config('agent.mcp_stdio_binary_allowlist', []);

        return in_array($binary, $allowlist, true);
    }

    /** Existing Boruna Tool for the team, or null. */
    public function toolForTeam(string $teamId): ?Tool
    {
        return Tool::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('subkind', 'boruna')
            ->first();
    }

    /**
     * High-level status used by UI banners.
     *
     * @return 'binary_missing'|'not_allowlisted'|'ready_to_enable'|'enabled'
     */
    public function statusForTeam(string $teamId, string $binary = RegisterBorunaToolAction::DEFAULT_BINARY): string
    {
        if (! $this->isBinaryAvailable($binary)) {
            return 'binary_missing';
        }

        if (! $this->isBinaryAllowed($binary)) {
            return 'not_allowlisted';
        }

        if ($this->toolForTeam($teamId) !== null) {
            return 'enabled';
        }

        return 'ready_to_enable';
    }
}
