<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\AgentChatProtocol\Actions\DispatchChatMessageAction;
use App\Domain\AgentChatProtocol\Actions\DispatchStructuredRequestAction;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Models\WorkflowNode;

/**
 * Executes a PlaybookStep whose underlying WorkflowNode is of type `external_agent`.
 *
 * Designed to be called from ExecutePlaybookStepJob when the step's node.type is
 * WorkflowNodeType::ExternalAgent. Resolves the external agent from node.config,
 * dispatches the chat/structured message, and returns the step output payload.
 */
class WorkflowExternalAgentHandler
{
    public function __construct(
        private readonly DispatchChatMessageAction $chatAction,
        private readonly DispatchStructuredRequestAction $structuredAction,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(PlaybookStep $step, WorkflowNode $node, array $input): array
    {
        $config = (array) $node->config;
        $externalAgentId = (string) ($config['external_agent_id'] ?? '');
        if ($externalAgentId === '') {
            throw new \RuntimeException('external_agent node missing config.external_agent_id');
        }

        $teamId = (string) ($step->team_id ?? $node->workflow?->team_id ?? '');
        $externalAgent = ExternalAgent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($externalAgentId);

        if ($externalAgent === null) {
            throw new \RuntimeException("external_agent {$externalAgentId} not found for team {$teamId}");
        }

        $mode = (string) ($config['mode'] ?? 'chat');
        $sessionToken = (string) ($config['session_token'] ?? $step->id);

        if ($mode === 'structured') {
            $schema = (array) ($config['schema'] ?? []);
            $prompt = (string) ($input['prompt'] ?? $input['context'] ?? '');

            return $this->structuredAction->execute(
                externalAgent: $externalAgent,
                prompt: $prompt,
                schema: $schema,
                sessionToken: $sessionToken,
                from: 'fleetq:workflow:'.($step->workflow_node_id ?? ''),
            );
        }

        $content = (string) ($input['prompt'] ?? $input['context'] ?? '');

        return $this->chatAction->execute(
            externalAgent: $externalAgent,
            content: $content,
            sessionToken: $sessionToken,
            from: 'fleetq:workflow:'.($step->workflow_node_id ?? ''),
        );
    }
}
