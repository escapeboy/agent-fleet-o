<?php

namespace App\Domain\Chatbot\Listeners;

use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class DeliverChatbotWorkflowResultListener implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(ExperimentTransitioned $event): void
    {
        // Only act on terminal states
        if (! in_array($event->toState->value, ['completed', 'execution_failed', 'killed'])) {
            return;
        }

        $experiment = $event->experiment;
        $constraints = $experiment->constraints ?? [];
        $messageId = $constraints['chatbot_message_id'] ?? null;

        if (! $messageId) {
            return;
        }

        $message = ChatbotMessage::find($messageId);

        if (! $message || $message->content !== null) {
            return;
        }

        // Collect output from the last completed agent step
        $reply = $this->extractReply($experiment->id);

        if (! $reply) {
            $reply = $experiment->result ?? $message->fallback_message ?? '';
        }

        $message->update([
            'content' => $reply ?: null,
            'was_escalated' => false,
        ]);

        // Update session stats
        $sessionId = $constraints['chatbot_session_id'] ?? null;

        if ($sessionId) {
            $session = ChatbotSession::find($sessionId);
            $session?->increment('message_count', 2);
            $session?->update(['last_activity_at' => now()]);
        }

        Log::info('DeliverChatbotWorkflowResult: message delivered', [
            'message_id' => $messageId,
            'experiment_id' => $experiment->id,
            'reply_length' => mb_strlen($reply),
        ]);
    }

    private function extractReply(string $experimentId): ?string
    {
        $step = PlaybookStep::where('experiment_id', $experimentId)
            ->where('status', 'completed')
            ->orderByDesc('order')
            ->first();

        if (! $step || ! is_array($step->output)) {
            return null;
        }

        return $step->output['content']
            ?? $step->output['result']
            ?? $step->output['response']
            ?? (is_string($step->output['output'] ?? null) ? $step->output['output'] : null);
    }
}
