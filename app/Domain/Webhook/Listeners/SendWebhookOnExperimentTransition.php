<?php

namespace App\Domain\Webhook\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Webhook\Actions\SendWebhookAction;

class SendWebhookOnExperimentTransition
{
    public function __construct(
        private SendWebhookAction $sendWebhook,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        $experiment = $event->experiment;
        $webhookEvent = match ($event->toState) {
            ExperimentStatus::Completed => 'experiment.completed',
            ExperimentStatus::ExecutionFailed,
            ExperimentStatus::PlanningFailed,
            ExperimentStatus::BuildingFailed,
            ExperimentStatus::ScoringFailed,
            ExperimentStatus::Killed => 'experiment.failed',
            default => null,
        };

        if (! $webhookEvent) {
            return;
        }

        $this->sendWebhook->execute(
            event: $webhookEvent,
            data: [
                'id' => $experiment->id,
                'title' => $experiment->title,
                'status' => $event->toState->value,
                'previous_status' => $event->fromState->value,
                'workflow_id' => $experiment->workflow_id,
                'budget_spent_credits' => $experiment->budget_spent_credits,
                'iteration' => $experiment->current_iteration,
            ],
            teamId: $experiment->team_id,
        );
    }
}
