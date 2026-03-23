<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\AgentSandboxOrchestrator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AgentSandboxTool extends Tool
{
    protected string $name = 'agent_sandbox_run';

    protected string $description = 'Run a shell command inside a dedicated Docker sandbox container for the given agent (enterprise plan only). Requires sandbox_profile to be configured on the agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID whose sandbox profile to use')
                ->required(),
            'command' => $schema->string()
                ->description('Shell command to execute inside the sandbox container')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'command' => 'required|string',
        ]);

        // Check plan feature: agent_process_isolation (cloud-only; base always allows)
        if (app()->bound('App\Domain\Shared\Services\PlanEnforcer')) {
            try {
                $planEnforcer = app('App\Domain\Shared\Services\PlanEnforcer');
                if (! $planEnforcer->hasFeature('agent_process_isolation')) {
                    return Response::error('agent_process_isolation is not available on your current plan. Upgrade to the Enterprise plan to use Docker-based per-execution agent process isolation.');
                }
            } catch (\Throwable) {
                // Silently allow if enforcer fails — base/community edition has no PlanEnforcer
            }
        }

        $agent = Agent::find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $orchestrator = app(AgentSandboxOrchestrator::class);

        if (! $orchestrator->isEnabled($agent)) {
            return Response::error("Agent '{$agent->name}' does not have a sandbox_profile configured. Set sandbox_profile via agent_update before running sandbox commands.");
        }

        try {
            $result = $orchestrator->run($agent, $validated['command']);

            return Response::text(json_encode([
                'agent_id' => $agent->id,
                'command' => $validated['command'],
                'exit_code' => $result['exit_code'],
                'successful' => $result['successful'],
                'stdout' => $result['stdout'],
                'stderr' => $result['stderr'],
            ]));
        } catch (\Throwable $e) {
            return Response::error('Sandbox execution failed: '.$e->getMessage());
        }
    }
}
