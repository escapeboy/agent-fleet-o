<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Models\User;

/**
 * Authorization gate for the VPS-installed Claude Code provider.
 *
 * Access is granted to super-admins or to members of teams that a super-admin
 * has explicitly flagged with `claude_code_vps_allowed = true`. The gate also
 * short-circuits when the feature is not configured (no OAuth token) or when
 * local agents are globally disabled.
 */
class ClaudeCodeVpsGate
{
    public function isConfigured(): bool
    {
        if (! config('local_agents.enabled')) {
            return false;
        }

        $token = config('local_agents.vps.oauth_token');

        return is_string($token) && $token !== '';
    }

    public function isAllowedForUser(?User $user, ?Team $team): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        if (! $user) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        return (bool) ($team?->claude_code_vps_allowed);
    }

    public function assertAllowed(?User $user, ?Team $team): void
    {
        if (! $this->isConfigured()) {
            throw VpsLocalAgentException::notConfigured();
        }

        if (! $this->isAllowedForUser($user, $team)) {
            throw VpsLocalAgentException::notAllowed();
        }
    }
}
