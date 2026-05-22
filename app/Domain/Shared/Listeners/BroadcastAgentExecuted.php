<?php

namespace App\Domain\Shared\Listeners;

use App\Domain\Agent\Events\AgentExecuted;
use App\Domain\Shared\Events\TeamActivityBroadcast;

class BroadcastAgentExecuted
{
    public function handle(AgentExecuted $event): void
    {
        $teamId = $event->agent->team_id;
        if (! $teamId) {
            return;
        }

        $verb = $event->succeeded ? 'completed' : 'failed';
        $input = $event->execution->input ?? [];
        $task = is_array($input)
            ? ($input['task'] ?? $input['content'] ?? $input['query'] ?? null)
            : null;
        $summary = is_string($task) && trim($task) !== ''
            ? "{$verb}: ".str($task)->limit(80)
            : "{$verb} run";

        TeamActivityBroadcast::dispatch(
            teamId: $teamId,
            kind: 'agent.executed',
            actorId: $event->agent->id,
            actorKind: 'agent',
            actorLabel: $event->agent->name,
            summary: $summary,
            at: ($event->execution->updated_at ?? now())->toIso8601String(),
            extra: [
                'execution_id' => $event->execution->id,
                'succeeded' => $event->succeeded,
                'duration_ms' => $event->execution->duration_ms,
            ],
        );
    }
}
