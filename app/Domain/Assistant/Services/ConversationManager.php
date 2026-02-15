<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;

class ConversationManager
{
    private const MAX_CONTEXT_MESSAGES = 30;

    private const MAX_ESTIMATED_TOKENS = 50_000;

    public function getOrCreateConversation(
        string $userId,
        string $teamId,
        ?string $conversationId = null,
        ?string $contextType = null,
        ?string $contextId = null,
    ): AssistantConversation {
        if ($conversationId) {
            $conversation = AssistantConversation::where('user_id', $userId)->find($conversationId);
            if ($conversation) {
                return $conversation;
            }
        }

        return AssistantConversation::create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'context_type' => $contextType,
            'context_id' => $contextId,
            'last_message_at' => now(),
        ]);
    }

    public function addMessage(
        AssistantConversation $conversation,
        string $role,
        string $content,
        ?array $toolCalls = null,
        ?array $toolResults = null,
        ?array $tokenUsage = null,
        array $metadata = [],
    ): AssistantMessage {
        $message = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => $role,
            'content' => $content,
            'tool_calls' => $toolCalls,
            'tool_results' => $toolResults,
            'token_usage' => $tokenUsage,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /**
     * Build message history for LLM context, with sliding window truncation.
     *
     * @return array<array{role: string, content: string}>
     */
    public function buildMessageHistory(AssistantConversation $conversation): array
    {
        $messages = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('created_at')
            ->limit(self::MAX_CONTEXT_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $estimatedTokens = 0;
        $result = [];

        foreach ($messages as $message) {
            $messageTokens = (int) ceil(mb_strlen($message->content) / 4);
            if ($estimatedTokens + $messageTokens > self::MAX_ESTIMATED_TOKENS) {
                break;
            }
            $estimatedTokens += $messageTokens;
            $result[] = [
                'role' => $message->role,
                'content' => $message->content,
            ];
        }

        return $result;
    }

    /**
     * Get recent conversations for the sidebar list.
     *
     * @return \Illuminate\Database\Eloquent\Collection<AssistantConversation>
     */
    public function getRecentConversations(string $userId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return AssistantConversation::where('user_id', $userId)
            ->orderByDesc('last_message_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Auto-generate a title from the first user message.
     */
    public function generateTitle(AssistantConversation $conversation): void
    {
        if ($conversation->title) {
            return;
        }

        $firstMessage = $conversation->messages()
            ->where('role', 'user')
            ->orderBy('created_at')
            ->first();

        if ($firstMessage) {
            $title = mb_substr($firstMessage->content, 0, 80);
            if (mb_strlen($firstMessage->content) > 80) {
                $title .= '...';
            }
            $conversation->update(['title' => $title]);
        }
    }
}
