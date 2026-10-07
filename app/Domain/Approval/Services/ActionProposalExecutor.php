<?php

namespace App\Domain\Approval\Services;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Assistant\Services\AssistantToolRegistry;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\GitRepository\Services\GitProvenanceContext;
use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolApprovalGate;
use App\Domain\Tool\Services\ToolDefinitionPinner;
use App\Models\User;
use Prism\Prism\Tool as PrismToolObject;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use ReflectionProperty;
use RuntimeException;

/**
 * Resolves an approved ActionProposal back to a concrete operation and
 * runs it. Supports tool_call (assistant), agent_tool_call, integration_action,
 * git_push and mcp_tool_definitions; other types throw
 * an unsupported error and the caller marks the proposal as
 * ExecutionFailed.
 */
class ActionProposalExecutor
{
    public function __construct(
        private readonly AssistantToolRegistry $toolRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(ActionProposal $proposal, User $actor): array
    {
        return match ($proposal->target_type) {
            'tool_call' => $this->executeToolCall($proposal, $actor),
            ToolApprovalGate::TARGET_TYPE => $this->executeAgentToolCall($proposal),
            ToolDefinitionPinner::TARGET_TYPE => $this->executeMcpToolDefinitions($proposal, $actor),
            'integration_action' => $this->executeIntegrationAction($proposal, $actor),
            'git_push' => $this->executeGitPush($proposal, $actor),
            default => throw new RuntimeException(
                "ActionProposalExecutor: unsupported target_type '{$proposal->target_type}'.",
            ),
        };
    }

    /**
     * Applies the MCP tool definitions held by ToolDefinitionPinner. Fails when
     * the server changed again after the proposal was made (stale hash).
     *
     * @return array<string, mixed>
     */
    private function executeMcpToolDefinitions(ActionProposal $proposal, User $actor): array
    {
        if (! ToolDefinitionPinner::canApprove($actor, (string) $proposal->team_id)) {
            throw new RuntimeException('ActionProposalExecutor: only a team owner or admin can apply MCP tool definition changes.');
        }

        $toolId = $proposal->payload['tool_id'] ?? null;
        $hash = $proposal->payload['pending_hash'] ?? null;

        if (! is_string($toolId) || ! is_string($hash)) {
            throw new RuntimeException('ActionProposalExecutor: mcp_tool_definitions payload requires tool_id and pending_hash.');
        }

        $tool = Tool::withoutGlobalScopes()
            ->where('team_id', $proposal->team_id)
            ->findOrFail($toolId);

        app(ToolDefinitionPinner::class)->approvePending($tool, $hash);

        return ['tool_id' => $tool->id, 'applied_hash' => $hash];
    }

    /**
     * Re-runs the gated git operation, bypassing GitOperationGate via the
     * `git_gate.bypass` container binding so we don't loop back into
     * another proposal-creation pass.
     *
     * @return array<string, mixed>
     */
    private function executeGitPush(ActionProposal $proposal, User $actor): array
    {
        $repositoryId = $proposal->payload['repository_id'] ?? null;
        $method = $proposal->payload['method'] ?? null;
        $args = $proposal->payload['args'] ?? [];

        if (! is_string($repositoryId) || ! is_string($method)) {
            throw new RuntimeException(
                'ActionProposalExecutor: git_push payload requires repository_id and method.',
            );
        }
        if (! is_array($args)) {
            throw new RuntimeException(
                'ActionProposalExecutor: git_push payload.args must be an array.',
            );
        }

        $repo = GitRepository::query()
            ->withoutGlobalScopes()
            ->where('team_id', $proposal->team_id)
            ->find($repositoryId);

        if (! $repo) {
            throw new RuntimeException(
                "ActionProposalExecutor: git repository {$repositoryId} not found in team {$proposal->team_id}.",
            );
        }

        app()->instance('git_gate.bypass', true);
        try {
            $client = app(GitOperationRouter::class)->resolve($repo);
            $provenance = is_array($proposal->payload['provenance'] ?? null) ? $proposal->payload['provenance'] : [];
            $result = app(GitProvenanceContext::class)->with(
                [
                    'team_id' => (string) $proposal->team_id,
                    'experiment_id' => $provenance['experiment_id'] ?? null,
                    'agent_id' => $provenance['agent_id'] ?? null,
                    'skill_execution_id' => $provenance['skill_execution_id'] ?? null,
                    'source' => 'approval_replay',
                ],
                fn () => $this->invokeGitMethod($client, $method, $args),
            );
        } finally {
            app()->instance('git_gate.bypass', false);
        }

        if (is_array($result)) {
            return $result;
        }
        if (is_string($result)) {
            return ['raw' => $result];
        }
        if ($result === null) {
            return ['success' => true];
        }

        return ['raw' => is_scalar($result) ? (string) $result : null];
    }

    /**
     * Dispatches the named git client method with the originally-stored
     * positional args. Args are validated by the gate at proposal-creation
     * time (one of the explicit RISK_MAP methods); we re-validate here
     * defensively to keep the proposal payload contract narrow.
     *
     * @param  array<string, mixed>  $args
     */
    private function invokeGitMethod(GitClientInterface $client, string $method, array $args): mixed
    {
        return match ($method) {
            'writeFile' => $client->writeFile(
                (string) ($args['path'] ?? ''),
                (string) ($args['content'] ?? ''),
                (string) ($args['message'] ?? ''),
                (string) ($args['branch'] ?? ''),
            ),
            'createBranch' => $client->createBranch(
                (string) ($args['branch'] ?? ''),
                (string) ($args['from'] ?? ''),
            ),
            'commit' => $client->commit(
                is_array($args['changes'] ?? null) ? $args['changes'] : [],
                (string) ($args['message'] ?? ''),
                (string) ($args['branch'] ?? ''),
            ),
            'push' => $client->push((string) ($args['branch'] ?? '')),
            'createPullRequest' => $client->createPullRequest(
                (string) ($args['title'] ?? ''),
                (string) ($args['body'] ?? ''),
                (string) ($args['head'] ?? ''),
                (string) ($args['base'] ?? ''),
            ),
            'mergePullRequest' => $client->mergePullRequest(
                (int) ($args['pr_number'] ?? 0),
                (string) ($args['method'] ?? 'squash'),
                isset($args['commit_title']) ? (string) $args['commit_title'] : null,
                isset($args['commit_message']) ? (string) $args['commit_message'] : null,
            ),
            'closePullRequest' => $client->closePullRequest((int) ($args['pr_number'] ?? 0)),
            'dispatchWorkflow' => $client->dispatchWorkflow(
                (string) ($args['workflow_id'] ?? ''),
                (string) ($args['ref'] ?? 'main'),
                is_array($args['inputs'] ?? null) ? array_map('strval', $args['inputs']) : [],
            ),
            'createRelease' => $client->createRelease(
                (string) ($args['tag_name'] ?? ''),
                (string) ($args['name'] ?? ''),
                (string) ($args['body'] ?? ''),
                (string) ($args['target_commitish'] ?? 'main'),
                (bool) ($args['draft'] ?? false),
                (bool) ($args['prerelease'] ?? false),
            ),
            default => throw new RuntimeException(
                "ActionProposalExecutor: git method '{$method}' is not gated/replayable.",
            ),
        };
    }

    /**
     * Re-runs the gated integration action, bypassing IntegrationActionGate
     * via the `integration_gate.bypass` container binding so we don't loop
     * back into another proposal-creation pass.
     *
     * @return array<string, mixed>
     */
    private function executeIntegrationAction(ActionProposal $proposal, User $actor): array
    {
        $integrationId = $proposal->payload['integration_id'] ?? null;
        $action = $proposal->payload['action'] ?? null;
        $params = $proposal->payload['params'] ?? [];

        if (! is_string($integrationId) || ! is_string($action)) {
            throw new RuntimeException(
                'ActionProposalExecutor: integration_action payload requires integration_id and action.',
            );
        }
        if (! is_array($params)) {
            throw new RuntimeException(
                'ActionProposalExecutor: integration_action payload.params must be an array.',
            );
        }

        $integration = Integration::query()
            ->withoutGlobalScopes()
            ->where('team_id', $proposal->team_id)
            ->find($integrationId);

        if (! $integration) {
            throw new RuntimeException(
                "ActionProposalExecutor: integration {$integrationId} not found in team {$proposal->team_id}.",
            );
        }

        app()->instance('integration_gate.bypass', true);
        try {
            $result = app(ExecuteIntegrationActionAction::class)->execute($integration, $action, $params);
        } finally {
            app()->instance('integration_gate.bypass', false);
        }

        if (is_array($result)) {
            return $result;
        }
        if (is_string($result)) {
            $decoded = json_decode($result, true);

            return is_array($decoded) ? $decoded : ['raw' => $result];
        }

        return ['raw' => is_scalar($result) ? (string) $result : null];
    }

    /** Stored replay output is capped; the full output is not needed to audit the call. */
    private const AGENT_TOOL_RESULT_MAX_CHARS = 20_000;

    /**
     * Replays an approved agent tool call (approval_mode=ask, or an argument
     * predicate that required approval). Only the recorded tool row is rebuilt,
     * in the recorded execution context (ResolveAgentToolsAction::resolveToolForReplay),
     * so a tool that was detached, denied or deactivated since fails instead of
     * running, and a different tool with the same name cannot run in its place.
     * ToolApprovalGate::withBypass lets exactly this call past the gate that held it.
     *
     * @return array<string, mixed>
     */
    private function executeAgentToolCall(ActionProposal $proposal): array
    {
        $payload = $proposal->payload;
        $toolName = $payload['tool'] ?? null;
        // An approver may have corrected the model's arguments (ApproveActionProposalAction).
        $edited = array_key_exists('edited_arguments', $payload);
        $arguments = $edited ? $payload['edited_arguments'] : ($payload['arguments'] ?? null);
        $agentId = $payload['agent_id'] ?? $proposal->actor_agent_id;

        if (! is_string($toolName) || $toolName === '') {
            throw new RuntimeException('ActionProposalExecutor: agent_tool_call payload.tool is missing or invalid.');
        }
        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new RuntimeException('ActionProposalExecutor: agent_tool_call payload.arguments must be an object.');
        }
        if (! is_string($agentId) || $agentId === '') {
            throw new RuntimeException('ActionProposalExecutor: agent_tool_call payload.agent_id is missing.');
        }

        $toolId = $payload['tool_id'] ?? null;
        if (! is_string($toolId) || $toolId === '') {
            throw new RuntimeException(
                'ActionProposalExecutor: agent_tool_call payload does not record which tool row was called; it cannot be replayed safely.',
            );
        }

        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $proposal->team_id)
            ->find($agentId);

        if (! $agent) {
            throw new RuntimeException("ActionProposalExecutor: agent {$agentId} not found in team {$proposal->team_id}.");
        }
        if ($agent->status === AgentStatus::Disabled) {
            throw new RuntimeException("ActionProposalExecutor: agent {$agentId} is disabled; the approved call was not run.");
        }

        $raw = ToolApprovalGate::withBypass($toolName, (string) $agent->id, function () use ($agent, $toolName, $toolId, $context, $arguments, $edited) {
            $matches = collect(app(ResolveAgentToolsAction::class)->resolveToolForReplay($agent, $toolId, $context))
                ->filter(fn (PrismToolObject $t) => $t->name() === $toolName)
                ->values();

            if ($matches->isEmpty()) {
                throw new RuntimeException(
                    "ActionProposalExecutor: tool '{$toolName}' (row {$toolId}) is no longer available to agent {$agent->id} in the original context (detached, denied, deactivated or outside the allowlist).",
                );
            }
            if ($matches->count() > 1) {
                throw new RuntimeException("ActionProposalExecutor: tool row {$toolId} yields more than one tool named '{$toolName}'; refusing an ambiguous replay.");
            }

            $tool = $matches->first();
            // Only a person's edits are checked here: MCP tools take variadic named
            // arguments, so the model's own call may legitimately carry extra keys.
            $unknown = $edited ? array_diff(array_map('strval', array_keys($arguments)), array_map('strval', array_keys($tool->parameters()))) : [];
            if ($unknown !== []) {
                throw new RuntimeException(
                    "ActionProposalExecutor: tool '{$toolName}' has no parameter(s) ".implode(', ', $unknown).'; the approved call was not run.',
                );
            }

            return $tool->handle(...$arguments);
        });

        if ($raw instanceof ToolError) {
            throw new RuntimeException($raw->message);
        }

        $output = $raw instanceof ToolOutput ? $raw->result : (string) $raw;
        if (mb_strlen($output) > self::AGENT_TOOL_RESULT_MAX_CHARS) {
            return ['raw' => mb_substr($output, 0, self::AGENT_TOOL_RESULT_MAX_CHARS), 'truncated' => true];
        }

        $decoded = json_decode($output, true);

        return is_array($decoded) ? $decoded : ['raw' => $output];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeToolCall(ActionProposal $proposal, User $actor): array
    {
        $toolName = $proposal->payload['tool'] ?? null;
        $args = $proposal->payload['positional_args'] ?? null;

        if (! is_string($toolName) || $toolName === '') {
            throw new RuntimeException('ActionProposalExecutor: payload.tool is missing or invalid.');
        }
        if (! is_array($args)) {
            throw new RuntimeException('ActionProposalExecutor: payload.positional_args is missing or invalid.');
        }

        // Get tools resolved against the actor's role/team — these are NOT
        // wrapped by the slow-mode gate (the gate is only applied inside
        // SendAssistantMessageAction). So invoking them directly bypasses
        // the proposal-creation loop and runs the real action.
        $tools = $this->toolRegistry->getTools($actor);
        $tool = collect($tools)->first(fn (PrismToolObject $t) => $t->name() === $toolName);

        if (! $tool) {
            throw new RuntimeException(
                "ActionProposalExecutor: tool '{$toolName}' is not visible to actor (role downgrade or removed).",
            );
        }

        $fnProperty = new ReflectionProperty(PrismToolObject::class, 'fn');
        $fn = $fnProperty->getValue($tool);

        $raw = $fn(...$args);

        // Tool fns return either string (often JSON) or scalar/array.
        // Normalize to an array we can persist into jsonb.
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : ['raw' => $raw];
        }
        if (is_array($raw)) {
            return $raw;
        }

        return ['raw' => is_scalar($raw) ? (string) $raw : null];
    }
}
