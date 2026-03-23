<?php

namespace App\Console\Commands;

use App\Domain\Agent\DTOs\AgentHeartbeatTask;
use App\Domain\Agent\Jobs\ExecuteAgentHeartbeatJob;
use App\Domain\Agent\Models\Agent;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates all active agents with heartbeat definitions and dispatches
 * ExecuteAgentHeartbeatJob for those whose next_run_at is in the past.
 *
 * Runs every minute via the scheduler (same cadence as DispatchScheduledProjectsJob).
 */
class EvaluateAgentHeartbeatsCommand extends Command
{
    protected $signature = 'agents:heartbeats';

    protected $description = 'Evaluate and dispatch due agent heartbeat tasks';

    public function handle(): int
    {
        $agents = Agent::withoutGlobalScopes()
            ->whereNotNull('heartbeat_definition')
            ->whereRaw("heartbeat_definition->>'enabled' = 'true'")
            ->whereRaw("heartbeat_definition->>'next_run_at' <= ?", [now()->toIso8601String()])
            ->orWhere(function ($q) {
                $q->whereNotNull('heartbeat_definition')
                    ->whereRaw("heartbeat_definition->>'enabled' = 'true'")
                    ->whereRaw("heartbeat_definition->>'next_run_at' IS NULL");
            })
            ->with('team')
            ->get();

        $dispatched = 0;

        foreach ($agents as $agent) {
            try {
                $task = AgentHeartbeatTask::fromArray($agent->heartbeat_definition ?? []);

                if (! $task->isDue()) {
                    continue;
                }

                ExecuteAgentHeartbeatJob::dispatch(
                    $agent->id,
                    $agent->team_id,
                    $task->prompt,
                );

                // Advance next_run_at to the next occurrence after now
                $nextRun = (new CronExpression($task->cron))->getNextRunDate(now()->toDateTimeImmutable());

                $updated = new AgentHeartbeatTask(
                    enabled: $task->enabled,
                    cron: $task->cron,
                    prompt: $task->prompt,
                    nextRunAt: Carbon::instance($nextRun),
                );

                $agent->withoutGlobalScopes()->where('id', $agent->id)->update([
                    'heartbeat_definition' => $updated->toArray(),
                ]);

                $dispatched++;
                Log::info("agents:heartbeats: dispatched heartbeat for agent {$agent->id} (next: {$nextRun->format('Y-m-d H:i:s')})");
            } catch (\Throwable $e) {
                Log::warning('agents:heartbeats: failed to dispatch heartbeat', [
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} agent heartbeat(s).");
        }

        return self::SUCCESS;
    }
}
