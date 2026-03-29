<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\DTOs\AgentHeartbeatTask;
use App\Domain\Agent\Jobs\ExecuteAgentHeartbeatJob;
use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: immediately dispatch an agent's heartbeat outside its normal schedule.
 *
 * Useful for testing the heartbeat prompt or triggering an ad-hoc
 * run without waiting for the next cron window.
 */
class AgentHeartbeatRunNowTool extends Tool
{
    protected string $name = 'agent_heartbeat_run_now';

    protected string $description = 'Immediately dispatch the agent\'s heartbeat task, ignoring the cron schedule. '
        .'The agent must have a heartbeat_definition configured with a non-empty prompt.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        if (empty($agent->heartbeat_definition)) {
            return Response::error('Agent has no heartbeat_definition configured. Use agent_heartbeat_update to set one first.');
        }

        $task = AgentHeartbeatTask::fromArray($agent->heartbeat_definition);

        if (empty($task->prompt)) {
            return Response::error('Heartbeat prompt is empty. Update the heartbeat definition with a non-empty prompt.');
        }

        ExecuteAgentHeartbeatJob::dispatch(
            $agent->id,
            $agent->team_id,
            $task->prompt,
        );

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'prompt' => mb_substr($task->prompt, 0, 100).(strlen($task->prompt) > 100 ? '...' : ''),
            'message' => 'Heartbeat job dispatched to the experiments queue.',
        ]));
    }
}
