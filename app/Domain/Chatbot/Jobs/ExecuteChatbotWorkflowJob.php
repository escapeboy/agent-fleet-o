<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecuteChatbotWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private readonly string $chatbotId,
        private readonly string $sessionId,
        private readonly string $messageId,
        private readonly string $workflowId,
        private readonly string $userText,
        private readonly string $context,
        private readonly string $actorUserId,
        private readonly string $teamId,
    ) {}

    public function handle(
        CreateExperimentAction $createExperiment,
        TransitionExperimentAction $transition,
    ): void {
        $chatbot = Chatbot::find($this->chatbotId);
        $workflow = Workflow::find($this->workflowId);
        $message = ChatbotMessage::find($this->messageId);

        if (! $chatbot || ! $workflow || ! $message) {
            return;
        }

        try {
            $experiment = $createExperiment->execute(
                userId: $this->actorUserId,
                title: "Chatbot: {$chatbot->name}",
                thesis: $this->context,
                track: 'workflow',
                teamId: $this->teamId,
                workflowId: $this->workflowId,
                constraints: [
                    'chatbot_message_id' => $this->messageId,
                    'chatbot_session_id' => $this->sessionId,
                    'chatbot_id' => $this->chatbotId,
                    'auto_approve' => true,
                ],
            );

            $transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Chatbot workflow delegation',
            );

            $message->update([
                'metadata' => array_merge($message->metadata ?? [], [
                    'workflow_experiment_id' => $experiment->id,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('ExecuteChatbotWorkflowJob failed', [
                'chatbot_id' => $this->chatbotId,
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
