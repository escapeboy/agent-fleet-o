<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\DTOs\AgentHeartbeatTask;
use App\Domain\Agent\Models\Agent;
use Cron\CronExpression;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: update an agent's heartbeat schedule definition.
 *
 * Accepts a structured heartbeat config and persists it to
 * agents.heartbeat_definition. Use agent_heartbeat_run_now to
 * trigger an immediate execution outside the normal schedule.
 */
class AgentHeartbeatUpdateTool extends Tool
{
    protected string $name = 'agent_heartbeat_update';

    protected string $description = 'Set or update the recurring heartbeat schedule for an agent. '
        .'The scheduler evaluates this every minute and dispatches the agent when the cron expression fires.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'enabled' => $schema->boolean()
                ->description('Whether the heartbeat schedule is active')
                ->required(),
            'cron' => $schema->string()
                ->description('Cron expression defining the schedule (e.g. "0 * * * *" for hourly, "*/15 * * * *" for every 15 min)')
                ->required(),
            'prompt' => $schema->string()
                ->description('The prompt/task that will be sent to the agent on each heartbeat execution')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'enabled' => 'required|boolean',
            'cron' => 'required|string|max:100',
            'prompt' => 'required|string|max:2000',
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

        // Validate the cron expression before persisting
        try {
            new CronExpression($validated['cron']);
        } catch (\InvalidArgumentException $e) {
            return Response::error('Invalid cron expression: '.$e->getMessage());
        }

        $task = new AgentHeartbeatTask(
            enabled: (bool) $validated['enabled'],
            cron: $validated['cron'],
            prompt: $validated['prompt'],
            nextRunAt: null, // reset so it fires on next evaluation cycle
        );

        $agent->update(['heartbeat_definition' => $task->toArray()]);

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'heartbeat' => $task->toArray(),
        ]));
    }
}
