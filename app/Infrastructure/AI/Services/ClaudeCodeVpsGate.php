<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Models\User;

/**
 * Authorization gate for the VPS-installed Claude Code provider.
 *
 * Access is granted to super-admins or to members of teams that a super-admin
 * has explicitly flagged with `claude_code_vps_allowed = true`. The gate short-
 * circuits when the feature is not configured (no OAuth token set).
 *
 * NOTE: deliberately decoupled from `config('local_agents.enabled')`. The
 * cloud edition forces that global flag to false as a safety net against the
 * generic local-agent shell-execution path (see CloudServiceProvider). The
 * VPS Claude Code path has its own, stricter gates (token + super-admin OR
 * team whitelist + ephemeral cwd + audit), so it works independently of the
 * global kill switch.
 */
class ClaudeCodeVpsGate
{
    public function isConfigured(): bool
    {
        $token = config('local_agents.vps.oauth_token');

        return is_string($token) && $token !== '';
    }

    public function isAllowedForUser(?User $user, ?Team $team): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        // Team-level whitelist passes without a user — covers queue jobs and
        // automated calls (world model digest, experiment pipeline) where no
        // HTTP session is active but the team has been explicitly allowed.
        if ($team?->claude_code_vps_allowed) {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $user->is_super_admin;
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
