<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Enums\AgentHookPosition;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentToolLockout;
use Illuminate\Support\Facades\Log;

/**
 * Per-tool-call governance (Squad borrow). Before a mutating tool runs, this
 * decides whether to allow or block the call based on:
 *   1. Reviewer-lockouts — a rejection encoded into runtime state, so the same
 *      agent cannot re-touch a resource a human/reviewer locked.
 *   2. OnToolCall guardrail hooks — user-configured, deterministic boundaries
 *      ("blocked commands", PII, etc.) enforced at the tool boundary instead of
 *      being whispered into the prompt.
 *
 * A hard no-op when agent.tool_governance.enabled is off, so the legacy tool
 * path is byte-for-byte unchanged. Returns a deny reason, or null to allow.
 */
class ToolCallGovernor
{
    public function __construct(
        private readonly AgentHookExecutor $hookExecutor,
    ) {}

    /**
     * @param  array<string, mixed>  $args  The named tool-call arguments.
     * @return string|null  Deny reason when blocked, or null to allow.
     */
    public function assert(Agent $agent, string $toolName, array $args): ?string
    {
        if (! config('agent.tool_governance.enabled')) {
            return null;
        }

        $candidates = $this->candidates($toolName, $args);

        $lockReason = $this->lockoutReason($agent, $candidates);
        if ($lockReason !== null) {
            return $this->deny($agent, $toolName, 'reviewer_lockout', $lockReason);
        }

        $guardReason = $this->guardrailReason($agent, $toolName, $args);
        if ($guardReason !== null) {
            return $this->deny($agent, $toolName, 'guardrail', $guardReason);
        }

        return null;
    }

    /**
     * The strings a lockout's `resource` can match against: the tool name plus
     * every string argument (file path, command, …).
     *
     * @param  array<string, mixed>  $args
     * @return array<int, string>
     */
    private function candidates(string $toolName, array $args): array
    {
        $candidates = [$toolName];

        foreach ($args as $value) {
            if (is_string($value) && $value !== '') {
                $candidates[] = $value;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function lockoutReason(Agent $agent, array $candidates): ?string
    {
        $lockouts = AgentToolLockout::withoutGlobalScopes()
            ->where('team_id', $agent->team_id)
            ->whereNull('released_at')
            ->where(function ($q) use ($agent) {
                $q->whereNull('agent_id')->orWhere('agent_id', $agent->id);
            })
            ->get();

        foreach ($lockouts as $lockout) {
            if ($lockout->blocks($candidates)) {
                return $lockout->reason !== null && $lockout->reason !== ''
                    ? $lockout->reason
                    : "resource '{$lockout->resource}' is locked for review";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function guardrailReason(Agent $agent, string $toolName, array $args): ?string
    {
        $context = $this->hookExecutor->run(AgentHookPosition::OnToolCall, $agent, [
            'tool_name' => $toolName,
            'tool_input' => json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            'input' => $args,
        ]);

        if (! empty($context['cancel'])) {
            return is_string($context['cancel_reason'] ?? null) && $context['cancel_reason'] !== ''
                ? $context['cancel_reason']
                : 'blocked by OnToolCall guardrail';
        }

        return null;
    }

    private function deny(Agent $agent, string $toolName, string $kind, string $reason): string
    {
        Log::warning('ToolCallGovernor: blocked tool call', [
            'agent_id' => $agent->id,
            'team_id' => $agent->team_id,
            'tool' => $toolName,
            'kind' => $kind,
            'reason' => $reason,
        ]);

        return $reason;
    }
}
