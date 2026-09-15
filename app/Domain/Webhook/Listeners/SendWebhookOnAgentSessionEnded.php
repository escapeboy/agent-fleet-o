<?php

namespace App\Domain\Webhook\Listeners;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Webhook\Actions\SendWebhookAction;
use App\Domain\Webhook\Enums\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends agent.session.completed / failed / cancelled when an AgentSession moves
 * from an open status into a terminal one.
 *
 * Wired to the AgentSession `updated` model event, so every path is covered:
 * experiment transitions (CloseAgentSessionOnTerminal), cancel from MCP or the
 * admin page, and sessions started over MCP with no experiment at all. An
 * experiment-backed session that completes also triggers experiment.completed;
 * the two events describe different entities and endpoints subscribe to each.
 */
class SendWebhookOnAgentSessionEnded
{
    public function __construct(
        private readonly SendWebhookAction $sendWebhook,
    ) {}

    public function handle(AgentSession $session): void
    {
        if (! $session->wasChanged('status')) {
            return;
        }

        $status = $session->status;
        if (! $status->isTerminal()) {
            return;
        }

        $original = $session->getOriginal('status');
        $previous = $original instanceof AgentSessionStatus ? $original : AgentSessionStatus::tryFrom((string) $original);
        if ($previous?->isTerminal()) {
            return;
        }

        $event = match ($status) {
            AgentSessionStatus::Completed => WebhookEvent::AgentSessionCompleted,
            AgentSessionStatus::Failed => WebhookEvent::AgentSessionFailed,
            default => WebhookEvent::AgentSessionCancelled,
        };

        $data = self::sessionData($session) + ['previous_status' => $previous?->value];
        $teamId = (string) $session->team_id;

        // The status change may be inside a transaction (experiment transitions);
        // do not announce it unless it commits.
        DB::afterCommit(function () use ($event, $data, $teamId): void {
            try {
                $this->sendWebhook->execute(event: $event->value, data: $data, teamId: $teamId);
            } catch (Throwable $e) {
                // A webhook failure must never undo or block the session status change.
                report($e);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function sessionData(AgentSession $session): array
    {
        return [
            'id' => $session->id,
            'agent_id' => $session->agent_id,
            'experiment_id' => $session->experiment_id,
            'crew_execution_id' => $session->crew_execution_id,
            'status' => $session->status->value,
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
        ];
    }
}
