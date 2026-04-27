<?php

namespace App\Domain\Approval\Services;

use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Assistant\Services\AssistantToolRegistry;
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
            default => throw new RuntimeException(
                "ActionProposalExecutor: unsupported target_type '{$proposal->target_type}'."
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
                'ActionProposalExecutor: integration_action payload requires integration_id and action.'
            );
        }
        if (! is_array($params)) {
            throw new RuntimeException(
                'ActionProposalExecutor: integration_action payload.params must be an array.'
            );
        }

        $integration = Integration::query()
            ->withoutGlobalScopes()
            ->where('team_id', $proposal->team_id)
            ->find($integrationId);

        if (! $integration) {
            throw new RuntimeException(
                "ActionProposalExecutor: integration {$integrationId} not found in team {$proposal->team_id}."
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
                "ActionProposalExecutor: tool '{$toolName}' is not visible to actor (role downgrade or removed)."
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
