<?php

namespace App\Domain\Tool\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Tool\Enums\ApprovalTimeoutAction;
use App\Domain\Tool\Models\Tool;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Tool as PrismToolObject;
use ReflectionProperty;
use Throwable;

/**
 * Enforces agent_tool.approval_mode = ask.
 *
 * A gated tool never runs when the model calls it. The call is stored as an
 * `agent_tool_call` ActionProposal (tool name + named arguments) and the model
 * gets a short result naming the proposal. When a human approves it,
 * ExecuteActionProposalJob replays exactly that call: ActionProposalExecutor
 * re-resolves the agent's tools and calls the tool inside withBypass(), a
 * one-shot grant scoped to the tool name and the agent.
 *
 * FleetQ cannot suspend an LLM turn, so the run that asked does not wait; the
 * replay result is stored on the proposal.
 *
 * Timeout: expires_at = now + approval_timeout_minutes. ExpireStaleApprovalsAction
 * settles the proposal once that passes: deny and skip expire it, allow approves it.
 */
final class ToolApprovalGate
{
    public const TARGET_TYPE = 'agent_tool_call';

    public const BYPASS_BINDING = 'agent_tool_approval.bypass';

    public const CONSUMER_ASK = 'ask';

    public const CONSUMER_PREDICATE = 'argument_predicate';

    public const DEFAULT_TIMEOUT_MINUTES = 30;

    public function __construct(
        private readonly CreateActionProposalAction $createProposal,
    ) {}

    /**
     * @param  array<int, PrismToolObject>  $prismTools
     * @param  array<string, mixed>  $replayContext  project_id, allowed_tool_ids, execution_id, sidecar_session_id of the calling execution
     * @return array<int, PrismToolObject>
     */
    public function wrap(array $prismTools, Agent $agent, Tool $tool, ?int $timeoutMinutes = null, ApprovalTimeoutAction|string|null $timeoutAction = null, array $replayContext = []): array
    {
        $minutes = $timeoutMinutes !== null && $timeoutMinutes > 0 ? $timeoutMinutes : self::DEFAULT_TIMEOUT_MINUTES;
        $action = $timeoutAction instanceof ApprovalTimeoutAction
            ? $timeoutAction
            : (ApprovalTimeoutAction::tryFrom((string) $timeoutAction) ?? ApprovalTimeoutAction::Deny);

        return array_map(
            fn (PrismToolObject $prismTool): PrismToolObject => $this->wrapOne($prismTool, $agent, $tool, $minutes, $action, $replayContext),
            $prismTools,
        );
    }

    /**
     * @param  array<string, mixed>  $replayContext
     */
    private function wrapOne(PrismToolObject $prismTool, Agent $agent, Tool $tool, int $minutes, ApprovalTimeoutAction $action, array $replayContext): PrismToolObject
    {
        $original = (new ReflectionProperty(PrismToolObject::class, 'fn'))->getValue($prismTool);
        $name = $prismTool->name();
        $paramNames = array_map('strval', array_keys($prismTool->parameters()));
        $teamId = (string) $agent->team_id;
        $agentId = (string) $agent->id;
        $toolId = (string) $tool->id;
        $createProposal = $this->createProposal;
        $context = self::replayContext($replayContext);

        $gated = clone $prismTool;
        $gated->for(rtrim($prismTool->description()).' Requires human approval: a call records an approval request and returns its id; the tool runs only after a person approves it.');
        $gated->using(function (...$args) use ($original, $name, $paramNames, $teamId, $agentId, $toolId, $minutes, $action, $createProposal, $context) {
            if (self::consumeBypass($name, self::CONSUMER_ASK, $agentId)) {
                return $original(...$args);
            }

            $arguments = self::namedArguments($args, $paramNames);

            try {
                $proposal = $createProposal->execute(
                    teamId: $teamId,
                    targetType: self::TARGET_TYPE,
                    targetId: $toolId,
                    summary: "Agent tool call needs approval: {$name}".self::summaryHint($arguments),
                    payload: [
                        'tool' => $name,
                        'arguments' => $arguments,
                        'agent_id' => $agentId,
                        'tool_id' => $toolId,
                        'source' => self::CONSUMER_ASK,
                        'timeout_action' => $action->value,
                        'context' => $context,
                    ],
                    agentId: $agentId,
                    riskLevel: 'high',
                    expiresAt: now()->addMinutes($minutes),
                );
            } catch (Throwable $e) {
                Log::warning('ToolApprovalGate: could not create approval request; call refused', [
                    'agent_id' => $agentId,
                    'tool' => $name,
                    'exception' => $e::class,
                ]);

                return "Tool {$name} requires human approval, but the approval request could not be created. The tool did not run.";
            }

            $status = $proposal->getAttribute('status');
            $statusValue = $status instanceof ActionProposalStatus ? $status->value : (string) $status;

            return match ($statusValue) {
                ActionProposalStatus::Approved->value => "Tool {$name} was approved automatically by policy (proposal_id={$proposal->id}) and is queued to run. Its result is recorded on the proposal, not returned here.",
                ActionProposalStatus::Rejected->value => "Tool {$name} was rejected by policy (proposal_id={$proposal->id}): ".Str::limit((string) $proposal->decision_reason, 300).' The tool did not run.',
                default => "Tool {$name} is waiting for human approval (proposal_id={$proposal->id}, window {$minutes} min). It has not run. Continue without its result, or report that approval is pending.",
            };
        });

        return $gated;
    }

    /**
     * Run $fn with a one-shot approval bypass for one tool of one agent. Each
     * gate (ask, argument predicate) can consume the grant once; the grant is
     * removed when $fn returns or throws.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function withBypass(string $toolName, string $agentId, Closure $fn): mixed
    {
        app()->instance(self::BYPASS_BINDING, [
            'tool' => $toolName,
            'agent_id' => $agentId,
            'consumers' => [self::CONSUMER_ASK => true, self::CONSUMER_PREDICATE => true],
        ]);

        try {
            return $fn();
        } finally {
            app()->forgetInstance(self::BYPASS_BINDING);
        }
    }

    public static function consumeBypass(string $toolName, string $consumer, string $agentId): bool
    {
        if (! app()->bound(self::BYPASS_BINDING)) {
            return false;
        }

        $grant = app(self::BYPASS_BINDING);

        if (! is_array($grant)
            || ($grant['tool'] ?? null) !== $toolName
            || ($grant['agent_id'] ?? null) !== $agentId
            || empty($grant['consumers'][$consumer])) {
            return false;
        }

        unset($grant['consumers'][$consumer]);
        app()->instance(self::BYPASS_BINDING, $grant);

        return true;
    }

    /**
     * The execution context an approved call is replayed in. Only known keys are
     * kept so nothing else from the resolver ends up in the proposal payload.
     *
     * @param  array<string, mixed>  $context
     * @return array{project_id: ?string, allowed_tool_ids: ?array<int, string>, execution_id: ?string, sidecar_session_id: ?string}
     */
    public static function replayContext(array $context): array
    {
        $string = fn (string $key): ?string => is_string($context[$key] ?? null) && $context[$key] !== '' ? $context[$key] : null;

        return [
            'project_id' => $string('project_id'),
            'allowed_tool_ids' => is_array($context['allowed_tool_ids'] ?? null)
                ? array_values(array_map('strval', $context['allowed_tool_ids']))
                : null,
            'execution_id' => $string('execution_id'),
            'sidecar_session_id' => $string('sidecar_session_id'),
        ];
    }

    /**
     * Prism passes the model's arguments by name. A positional call (a direct
     * handle('x') from code) is mapped onto the declared parameter order so the
     * stored call can be replayed by name.
     *
     * @param  array<int|string, mixed>  $args
     * @param  array<int, string>  $paramNames
     * @return array<string, mixed>
     */
    private static function namedArguments(array $args, array $paramNames): array
    {
        $named = [];
        foreach ($args as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            } elseif (isset($paramNames[$key])) {
                $named[$paramNames[$key]] = $value;
            }
        }

        return $named;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function summaryHint(array $arguments): string
    {
        foreach ($arguments as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                return ' ('.Str::limit((string) $value, 80).')';
            }
        }

        return '';
    }
}
