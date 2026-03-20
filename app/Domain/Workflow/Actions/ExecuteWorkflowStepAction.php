<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Models\Crew;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use Illuminate\Support\Facades\Log;

class ExecuteWorkflowStepAction
{
    public function __construct(
        private readonly ExecuteAgentAction $executeAgent,
        private readonly ExecuteCrewAction $executeCrew,
    ) {}

    /**
     * Execute a single workflow node in-process.
     * Follows the same pattern as ExecuteCrewTaskJob::handle().
     *
     * @param  array  $node  Graph node snapshot
     * @param  array  $input  Resolved inputs from predecessors
     * @return array Result data from execution
     */
    public function execute(
        array $node,
        array $input,
        string $teamId,
        string $userId,
        ?string $experimentId = null,
        int $depth = 0,
    ): array {
        $type = WorkflowNodeType::tryFrom($node['type'] ?? '');

        return match ($type) {
            WorkflowNodeType::Agent => $this->executeAgentNode($node, $input, $teamId, $userId, $experimentId, $depth),
            WorkflowNodeType::Crew => $this->executeCrewNode($node, $input, $teamId, $userId),
            WorkflowNodeType::SubWorkflow => ['result' => 'Sub-workflow execution not supported in synchronous mode'],
            WorkflowNodeType::BorunaStep => ['result' => 'Boruna step execution not supported in synchronous mode'],
            WorkflowNodeType::TimeGate => ['result' => 'Time gate skipped in synchronous mode', 'data' => $input],
            default => ['result' => 'Unknown node type: '.($node['type'] ?? 'null')],
        };
    }

    private function executeAgentNode(
        array $node,
        array $input,
        string $teamId,
        string $userId,
        ?string $experimentId,
        int $depth,
    ): array {
        $agentId = $node['agent_id'] ?? null;
        if (! $agentId) {
            return ['error' => 'No agent_id configured for agent node'];
        }

        $agent = Agent::withoutGlobalScopes()->find($agentId);
        if (! $agent || $agent->team_id !== $teamId || $agent->status !== AgentStatus::Active) {
            return ['error' => "Agent {$agentId} not available"];
        }

        $inputTemplate = $node['config']['input_template'] ?? null;
        $taskInput = $inputTemplate
            ? $this->interpolateTemplate($inputTemplate, $input)
            : ($input['result'] ?? json_encode($input));

        try {
            $result = $this->executeAgent->execute(
                agent: $agent,
                input: [
                    'task' => $taskInput,
                    '_is_nested_call' => true,
                    '_agent_tool_depth' => $depth + 1,
                ],
                teamId: $teamId,
                userId: $userId,
                experimentId: $experimentId,
            );

            $output = $result['output'] ?? null;

            return [
                'result' => is_string($output) ? $output : ($output['result'] ?? json_encode($output)),
                'agent_id' => $agentId,
                'cost_credits' => $result['execution']->cost_credits ?? 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('SynchronousWorkflow: agent node failed', [
                'node_id' => $node['id'] ?? null,
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    private function executeCrewNode(array $node, array $input, string $teamId, string $userId): array
    {
        $crewId = $node['crew_id'] ?? null;
        if (! $crewId) {
            return ['error' => 'No crew_id configured for crew node'];
        }

        $crew = Crew::withoutGlobalScopes()->find($crewId);
        if (! $crew || $crew->team_id !== $teamId) {
            return ['error' => "Crew {$crewId} not available"];
        }

        try {
            $goal = $input['result'] ?? json_encode($input);
            $execution = $this->executeCrew->execute($crew, $goal, $userId);

            return [
                'result' => $execution->final_output ?? 'Crew completed',
                'crew_id' => $crewId,
                'execution_id' => $execution->id,
            ];
        } catch (\Throwable $e) {
            Log::warning('SynchronousWorkflow: crew node failed', [
                'node_id' => $node['id'] ?? null,
                'crew_id' => $crewId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Simple mustache-style template interpolation.
     */
    private function interpolateTemplate(string $template, array $data): string
    {
        return preg_replace_callback('/\{\{(\w+(?:\.\w+)*)\}\}/', function ($matches) use ($data) {
            return data_get($data, $matches[1], $matches[0]);
        }, $template);
    }
}
