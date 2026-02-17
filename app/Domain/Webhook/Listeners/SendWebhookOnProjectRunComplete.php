<?php

namespace App\Domain\Webhook\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Webhook\Actions\SendWebhookAction;

class SendWebhookOnProjectRunComplete
{
    public function __construct(
        private SendWebhookAction $sendWebhook,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        if (! $event->toState->isTerminal() && ! $event->toState->isFailed()) {
            return;
        }

        $run = ProjectRun::where('experiment_id', $event->experiment->id)->first();
        if (! $run) {
            return;
        }

        /** @var Project|null $project */
        $project = $run->project;
        if (! $project) {
            return;
        }

        $isSuccess = $event->toState === ExperimentStatus::Completed;

        $webhookEvent = $isSuccess ? 'project.run.completed' : 'project.run.failed';

        /** @var string $teamId */
        $teamId = $project->team_id;

        $this->sendWebhook->execute(
            event: $webhookEvent,
            data: [
                'id' => $run->id,
                'project_id' => $project->id,
                'project_title' => $project->title,
                'run_number' => $run->run_number,
                'status' => $isSuccess ? ProjectRunStatus::Completed->value : ProjectRunStatus::Failed->value,
                'trigger' => $run->trigger,
                'experiment_id' => $event->experiment->id,
                'cost_credits' => $event->experiment->budget_spent_credits,
            ],
            teamId: $teamId,
        );
    }
}
