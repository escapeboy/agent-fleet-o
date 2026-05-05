<?php

declare(strict_types=1);

namespace App\Domain\Crew\Services;

use App\Domain\AgentChatProtocol\Actions\DispatchChatMessageAction;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches a crew task to an external agent via the Agent Chat Protocol.
 *
 * Called from CrewOrchestrator when a task has external_agent_id set (i.e. the
 * crew member is an external agent, not an internal Agent row).
 */
class CrewExternalMemberDispatcher
{
    public function __construct(private readonly DispatchChatMessageAction $chatAction) {}

    public function dispatch(CrewTaskExecution $task, CrewExecution $execution): void
    {
        $externalAgent = ExternalAgent::withoutGlobalScopes()
            ->where('team_id', $execution->team_id)
            ->find($task->external_agent_id);

        if ($externalAgent === null) {
            $task->update([
                'status' => CrewTaskStatus::Failed,
                'error_message' => "External agent {$task->external_agent_id} not found",
                'completed_at' => now(),
            ]);

            return;
        }

        $task->update([
            'status' => CrewTaskStatus::Running,
            'started_at' => now(),
        ]);

        $inputContext = (array) ($task->input_context ?? []);
        $content = (string) ($task->description ?? $inputContext['original_goal'] ?? '');

        try {
            $result = $this->chatAction->execute(
                externalAgent: $externalAgent,
                content: $content,
                sessionToken: 'crew:'.$execution->id.':task:'.$task->id,
                from: 'fleetq:crew:'.$execution->id,
            );

            $reply = $result['remote_response']['content']
                ?? $result['remote_response']['output']
                ?? $result['remote_response']['ack']
                ?? null;

            $task->update([
                'status' => CrewTaskStatus::Completed,
                'output' => is_string($reply) ? $reply : json_encode($reply),
                'completed_at' => now(),
            ]);

            Log::info('Crew external member dispatched successfully', [
                'task_id' => $task->id,
                'external_agent_id' => $externalAgent->id,
                'session_id' => $result['session_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Crew external member dispatch failed', [
                'task_id' => $task->id,
                'external_agent_id' => $externalAgent->id,
                'error' => $e->getMessage(),
            ]);

            $task->update([
                'status' => CrewTaskStatus::Failed,
                'error_message' => substr($e->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);
        }
    }
}
