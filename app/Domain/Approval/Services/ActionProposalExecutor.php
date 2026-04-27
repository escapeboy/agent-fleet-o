<?php

namespace App\Domain\Approval\Services;

use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Assistant\Services\AssistantToolRegistry;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Models\Integration;
use App\Models\User;
use Prism\Prism\Tool as PrismToolObject;
use ReflectionProperty;
use RuntimeException;

/**
 * Resolves an approved ActionProposal back to a concrete operation and
 * runs it. v1 supports `target_type='tool_call'` only; other types throw
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
            'integration_action' => $this->executeIntegrationAction($proposal, $actor),
            'git_push' => $this->executeGitPush($proposal, $actor),
            default => throw new RuntimeException(
                "ActionProposalExecutor: unsupported target_type '{$proposal->target_type}'.",
            ),
        };
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
            $result = $this->invokeGitMethod($client, $method, $args);
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
