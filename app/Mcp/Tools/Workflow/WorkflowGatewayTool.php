<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\SynchronousWorkflowExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Dynamically wraps an MCP-exposed workflow as a named MCP tool.
 * One instance is created per exposed workflow in AgentFleetServer::boot().
 */
#[IsDestructive]
class WorkflowGatewayTool extends Tool
{
    public function __construct(
        private readonly Workflow $workflow,
        private readonly SynchronousWorkflowExecutor $executor,
    ) {
        $this->name = $workflow->mcp_tool_name ?? 'workflow_'.$workflow->id;
        $this->description = $workflow->description
            ?? "Execute the '{$workflow->name}' workflow.";
    }

    public function schema(JsonSchema $schema): array
    {
        $inputSchema = $this->workflow->settings['input_schema'] ?? [];

        if (empty($inputSchema)) {
            return [
                'input' => $schema->string()
                    ->description('Input data for the workflow (JSON string or plain text)'),
            ];
        }

        $params = [];
        foreach ($inputSchema as $key => $definition) {
            $type = $definition['type'] ?? 'string';
            $description = $definition['description'] ?? $key;
            $required = $definition['required'] ?? false;

            $param = match ($type) {
                'integer', 'number' => $schema->integer()->description($description),
                'boolean' => $schema->boolean()->description($description),
                default => $schema->string()->description($description),
            };

            if ($required) {
                $param = $param->required();
            }

            $params[$key] = $param;
        }

        return $params;
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $userId = auth()->id() ?? $this->workflow->user_id;

        if (! $teamId) {
            return Response::error('No current team context.');
        }

        // Verify the workflow still belongs to this team and is still exposed
        $workflow = Workflow::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('mcp_exposed', true)
            ->find($this->workflow->id);

        if (! $workflow) {
            return Response::error('Workflow not found or gateway has been disabled.');
        }

        $input = $request->all();

        try {
            if ($workflow->mcp_execution_mode === 'sync') {
                $result = $this->executor->execute(
                    workflow: $workflow,
                    teamId: $teamId,
                    userId: $userId,
                    input: $input,
                );

                return Response::text($result);
            }

            // Async: create an experiment and return the experiment ID
            $experiment = app(CreateExperimentAction::class)->execute(
                teamId: $teamId,
                userId: $userId,
                name: "Gateway: {$workflow->name}",
                workflowId: $workflow->id,
                input: $input,
            );

            return Response::text(json_encode([
                'status' => 'dispatched',
                'experiment_id' => $experiment->id,
                'message' => "Workflow '{$workflow->name}' has been dispatched. Use experiment_get to check status.",
            ]));
        } catch (\Throwable $e) {
            return Response::error('Workflow execution failed: '.$e->getMessage());
        }
    }
}
