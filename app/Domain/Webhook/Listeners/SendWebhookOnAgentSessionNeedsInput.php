<?php

namespace App\Domain\Webhook\Listeners;

use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Webhook\Actions\SendWebhookAction;
use App\Domain\Webhook\Enums\WebhookEvent;
use Throwable;

/**
 * Sends agent.session.needs_input when an experiment with an open AgentSession
 * stops for a human: it moves to AwaitingApproval (a clarification question from
 * the agent, or a plan waiting for approval).
 *
 * Session lookup matches MirrorExperimentTransition: pinned to the experiment's
 * team, open statuses only, latest first.
 */
class SendWebhookOnAgentSessionNeedsInput
{
    public function __construct(
        private readonly SendWebhookAction $sendWebhook,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        if ($event->toState !== ExperimentStatus::AwaitingApproval) {
            return;
        }

        $session = AgentSession::withoutGlobalScopes()
            ->where('team_id', $event->experiment->team_id)
            ->where('experiment_id', $event->experiment->id)
            ->whereIn('status', ['pending', 'active', 'sleeping'])
            ->latest('created_at')
            ->first();

        if (! $session) {
            return;
        }

        try {
            $this->sendWebhook->execute(
                event: WebhookEvent::AgentSessionNeedsInput->value,
                data: SendWebhookOnAgentSessionEnded::sessionData($session) + [
                    'reason' => 'experiment_awaiting_approval',
                    'experiment_status' => $event->toState->value,
                    'previous_experiment_status' => $event->fromState->value,
                ],
                teamId: (string) $event->experiment->team_id,
            );
        } catch (Throwable $e) {
            // Listeners run inside the transition dispatch; a webhook failure must not break it.
            report($e);
        }
    }
}
