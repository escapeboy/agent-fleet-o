<?php

namespace App\Domain\Approval\Listeners;

use App\Domain\Approval\Events\ActionProposalExecuted;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * After an ActionProposal finishes executing (success or failure), append
 * the outcome to the originating assistant conversation as a new message
 * so the agent's next turn can pick up where it paused.
 *
 * The conversation reference comes from `payload.conversation_id` (set by
 * the slow-mode gate at proposal-creation time). If the proposal was
 * created outside an assistant conversation, this listener is a no-op.
 */
class AppendExecutionResultToConversation
{
    public function handle(ActionProposalExecuted $event): void
    {
        $proposal = $event->proposal;
        $conversationId = $proposal->payload['conversation_id'] ?? null;
        if (! is_string($conversationId) || $conversationId === '') {
            return;
        }

        $conversation = AssistantConversation::find($conversationId);
        if (! $conversation) {
            return;
        }

        // Build a compact human-readable summary that the LLM can also parse.
        if ($event->succeeded) {
            $resultJson = json_encode(
                $proposal->execution_result ?? [],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $content = "[Action approved and executed — proposal {$proposal->id}]\n"
                ."Tool: {$proposal->payload['tool']}\n"
                .'Result: '.Str::limit($resultJson ?: '{}', 1500);
        } else {
            $content = "[Action approved but execution failed — proposal {$proposal->id}]\n"
                ."Tool: {$proposal->payload['tool']}\n"
                .'Error: '.Str::limit((string) $proposal->execution_error, 1500);
        }

        try {
            AssistantMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $content,
                'metadata' => [
                    'kind' => 'action_proposal_result',
                    'proposal_id' => $proposal->id,
                    'succeeded' => $event->succeeded,
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AppendExecutionResultToConversation: failed to persist message', [
                'proposal_id' => $proposal->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
