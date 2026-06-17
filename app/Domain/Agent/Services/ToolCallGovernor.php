<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Enums\AgentHookPosition;
use App\Domain\Agent\Enums\AgentHookType;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentHook;
use App\Domain\Agent\Models\AgentToolLockout;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use Illuminate\Support\Facades\Log;

/**
 * Per-tool-call governance (Squad borrow). Before a mutating tool runs, this
 * decides whether to allow or block the call based on:
 *   1. Reviewer-lockouts — a rejection encoded into runtime state, so the same
 *      agent cannot re-touch a resource a human/reviewer locked.
 *   2. OnToolCall guardrail hooks — user-configured, deterministic boundaries
 *      ("blocked commands", PII, etc.) enforced at the tool boundary instead of
 *      being whispered into the prompt.
 *   3. Argument predicates (eve borrow) — input-conditioned rules that gate a
 *      call by the value of a specific argument (e.g. a query that would scan
 *      more than N GB). A match either blocks the call or raises an
 *      ActionProposal for human approval. Gated behind the
 *      tool_governance.argument_predicates sub-flag.
 *
 * A hard no-op when agent.tool_governance.enabled is off, so the legacy tool
 * path is byte-for-byte unchanged. Returns a deny reason, or null to allow.
 */
class ToolCallGovernor
{
    public function __construct(
        private readonly AgentHookExecutor $hookExecutor,
        private readonly ArgumentPredicateEvaluator $predicates,
    ) {}

    /**
     * @param  array<string, mixed>  $args  The named tool-call arguments.
     * @return string|null Deny reason when blocked, or null to allow.
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

        $predicateReason = $this->argumentPredicateReason($agent, $toolName, $args);
        if ($predicateReason !== null) {
            return $predicateReason;
        }

        return null;
    }

    /**
     * Evaluate input-conditioned argument predicates declared on the agent's
     * OnToolCall guardrail hooks (config.arg_predicates). A `block` match denies
     * the call; a `require_approval` match denies the autonomous call AND raises
     * an ActionProposal so a human can approve it from the inbox (FleetQ has no
     * tool-call-level suspend/resume, so the call cannot be paused mid-flight —
     * it is held and re-driven after approval).
     *
     * @param  array<string, mixed>  $args
     */
    private function argumentPredicateReason(Agent $agent, string $toolName, array $args): ?string
    {
        if (! config('agent.tool_governance.argument_predicates')) {
            return null;
        }

        $predicates = $this->collectPredicates($agent, $toolName);
        if ($predicates === []) {
            return null;
        }

        $match = $this->predicates->evaluate($predicates, $args);
        if ($match === null) {
            return null;
        }

        if ($match['action'] === 'require_approval') {
            $this->raiseApprovalProposal($agent, $toolName, $args, $match);

            return $this->deny(
                $agent,
                $toolName,
                'arg_predicate_approval',
                $match['reason'].' — held for human approval.',
            );
        }

        return $this->deny($agent, $toolName, 'arg_predicate_block', $match['reason']);
    }

    /**
     * Gather predicate definitions from the agent's enabled OnToolCall guardrail
     * hooks (class-level + instance-level). A predicate may scope itself to a
     * single tool via a `tool` key; absent that, it applies to every tool.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectPredicates(Agent $agent, string $toolName): array
    {
        $hooks = AgentHook::where('team_id', $agent->team_id)
            ->where('position', AgentHookPosition::OnToolCall)
            ->where('type', AgentHookType::Guardrail)
            ->where('enabled', true)
            ->where(function ($q) use ($agent) {
                $q->whereNull('agent_id')->orWhere('agent_id', $agent->id);
            })
            ->get();

        $out = [];
        foreach ($hooks as $hook) {
            $defs = $hook->config['arg_predicates'] ?? null;
            if (! is_array($defs)) {
                continue;
            }

            foreach ($defs as $def) {
                if (! is_array($def)) {
                    continue;
                }

                $scopedTool = $def['tool'] ?? null;
                if (is_string($scopedTool) && $scopedTool !== '' && $scopedTool !== $toolName) {
                    continue;
                }

                $out[] = $def;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array{action: string, reason: string, arg: string, op: string}  $match
     */
    private function raiseApprovalProposal(Agent $agent, string $toolName, array $args, array $match): void
    {
        try {
            app(CreateActionProposalAction::class)->execute(
                teamId: (string) $agent->team_id,
                targetType: 'tool_call',
                targetId: null,
                summary: "Tool '{$toolName}' needs approval: {$match['reason']}",
                payload: [
                    'tool_name' => $toolName,
                    'args' => $args,
                    'predicate' => ['arg' => $match['arg'], 'op' => $match['op']],
                ],
                agentId: $agent->id,
                riskLevel: 'high',
            );
        } catch (\Throwable $e) {
            Log::warning('ToolCallGovernor: failed to raise approval proposal', [
                'agent_id' => $agent->id,
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);
        }
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
