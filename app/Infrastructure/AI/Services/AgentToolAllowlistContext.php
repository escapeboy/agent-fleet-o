<?php

namespace App\Infrastructure\AI\Services;

/**
 * Mutable per-job/request holder for a crew member's tool allowlist, keyed by
 * agent id. Bound with app()->scoped() so it is flushed between queue jobs.
 *
 * Gateways that build their own tool set (e.g. LocalAgentGateway's Claude Code
 * --mcp-config) read it to honour CrewMember tool_allowlist. A null allowlist
 * means no restriction. The values are Tool ids, matched the same way as
 * ResolveAgentToolsAction::filterAgentTools.
 */
class AgentToolAllowlistContext
{
    public ?string $agentId = null;

    /** @var array<int, string>|null */
    public ?array $allowedToolIds = null;

    /**
     * Run $fn with the allowlist set for $agentId and restore the previous
     * values afterwards. An empty array is treated as no restriction (null).
     *
     * @param  array<int, string>|null  $allowedToolIds
     */
    public function with(string $agentId, ?array $allowedToolIds, callable $fn): mixed
    {
        $previousAgentId = $this->agentId;
        $previousAllowed = $this->allowedToolIds;

        $this->agentId = $agentId;
        $this->allowedToolIds = $allowedToolIds === [] ? null : $allowedToolIds;

        try {
            return $fn();
        } finally {
            $this->agentId = $previousAgentId;
            $this->allowedToolIds = $previousAllowed;
        }
    }

    /**
     * The allowed Tool ids for $agentId, or null when no restriction applies.
     *
     * @return array<int, string>|null
     */
    public function allowedToolIdsFor(?string $agentId): ?array
    {
        if ($agentId === null || $agentId !== $this->agentId) {
            return null;
        }

        return $this->allowedToolIds;
    }
}
