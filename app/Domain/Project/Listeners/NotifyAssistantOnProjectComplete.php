<?php

namespace App\Domain\Project\Listeners;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use App\Domain\Telegram\Actions\SendTelegramReplyAction;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramChatBinding;
use Illuminate\Support\Facades\Log;

class NotifyAssistantOnProjectComplete
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly SendTelegramReplyAction $sendTelegramReply,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        if (! $event->toState->isTerminal()) {
            return;
        }

        /** @var ProjectRun|null $run */
        $run = ProjectRun::withoutGlobalScopes()
            ->where('experiment_id', $event->experiment->id)
            ->whereNotNull('triggered_by_conversation_id')
            ->first();

        if (! $run) {
            return;
        }

        /** @var AssistantConversation|null $conversation */
        $conversation = $run->triggeredByConversation;
        if (! $conversation) {
            return;
        }

        /** @var Project|null $project */
        $project = $run->project;
        $teamId = $project?->team_id ?? $conversation->team_id;
        $isCompleted = $event->toState === ExperimentStatus::Completed;

        $title = $isCompleted
            ? "'{$project?->title}' agents finished"
            : "'{$project?->title}' agents encountered an issue";

        $body = $isCompleted
            ? "Your delegated project run completed successfully. {$run->output_summary}"
            : "The run ended with status: {$event->toState->value}.";

        // Find the user to notify (conversation owner or first team member)
        $userId = $conversation->user_id ?? $this->resolveTeamOwnerId($teamId);

        if ($userId) {
            $this->notificationService->notify(
                userId: $userId,
                teamId: $teamId,
                type: 'delegation_complete',
                title: $title,
                body: $body,
                actionUrl: $project ? route('projects.show', $project) : null,
                data: [
                    'run_id' => $run->id,
                    'conversation_id' => $conversation->id,
                    'status' => $event->toState->value,
                ],
            );
        }

        // Send Telegram notification if team has a bot and a chat binding for this conversation
        $this->sendTelegramNotification($teamId, $conversation->id, $title, $body, $run, $isCompleted);
    }

    private function sendTelegramNotification(
        ?string $teamId,
        string $conversationId,
        string $title,
        string $body,
        ProjectRun $run,
        bool $isCompleted,
    ): void {
        if (! $teamId) {
            return;
        }

        /** @var TelegramBot|null $bot */
        $bot = TelegramBot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', 'active')
            ->first();

        if (! $bot) {
            return;
        }

        /** @var TelegramChatBinding|null $binding */
        $binding = TelegramChatBinding::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('conversation_id', $conversationId)
            ->first();

        if (! $binding) {
            return;
        }

        $icon = $isCompleted ? '✅' : '❌';
        $message = "{$icon} <b>{$title}</b>\n\n{$body}";

        if ($run->project) {
            $message .= "\n\n<a href=\"{$this->projectUrl($run)}\">View project</a>";
        }

        try {
            $this->sendTelegramReply->execute($bot->bot_token, $binding->chat_id, $message);
        } catch (\Throwable $e) {
            Log::warning('NotifyAssistantOnProjectComplete: Failed to send Telegram notification', [
                'error' => $e->getMessage(),
                'run_id' => $run->id,
            ]);
        }
    }

    private function resolveTeamOwnerId(?string $teamId): ?string
    {
        if (! $teamId) {
            return null;
        }

        return Team::find($teamId)?->owner?->id;
    }

    private function projectUrl(ProjectRun $run): string
    {
        try {
            return route('projects.show', $run->project);
        } catch (\Throwable) {
            return '';
        }
    }
}
