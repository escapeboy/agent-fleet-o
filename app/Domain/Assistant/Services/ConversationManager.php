<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ConversationManager
{
    private const MAX_CONTEXT_MESSAGES = 30;

    private const MAX_ESTIMATED_TOKENS = 50_000;

    private const COMPACTION_THRESHOLD = 40;

    public function getOrCreateConversation(
        string $userId,
        string $teamId,
        ?string $conversationId = null,
        ?string $contextType = null,
        ?string $contextId = null,
    ): AssistantConversation {
        if ($conversationId) {
            $conversation = AssistantConversation::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('user_id', $userId)
                ->find($conversationId);
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
     * Build message history for LLM context, using semantic scoring
     * to prioritize high-value messages within the token budget.
     *
     * @return array<array{role: string, content: string}>
     */
    public function buildMessageHistory(AssistantConversation $conversation): array
    {
        // Trigger compaction if the conversation has grown past the threshold.
        if (config('context_compaction.enabled', true)) {
            $this->maybeCompact($conversation);
        }

        // Load the most recent pinned snapshot (if any) to prepend as context floor.
        $snapshot = $conversation->messages()
            ->where('role', 'system')
            ->where('metadata->is_snapshot', true)
            ->orderByDesc('created_at')
            ->first();

        // Fetch 2x candidates — only non-archived messages
        /** @var Collection<int, AssistantMessage> $messages */
        $messages = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->where(function ($q) {
                $q->whereNull('metadata->archived')
                    ->orWhere('metadata->archived', false);
            })
            ->orderByDesc('created_at')
            ->limit(self::MAX_CONTEXT_MESSAGES * 2)
            ->get()
            ->reverse()
            ->values();

        if ($messages->isEmpty()) {
            return [];
        }

        $total = $messages->count();

        // Score each message
        $scored = $messages->map(fn (AssistantMessage $msg, int $idx) => [
            'message' => $msg,
            'index' => $idx,
            'score' => $this->scoreMessage($msg, $idx, $total),
        ]);

        // Sort by score descending, then select within token budget
        $sorted = $scored->sortByDesc('score');

        $estimatedTokens = 0;
        $selected = [];

        foreach ($sorted as $item) {
            $messageTokens = (int) ceil(mb_strlen($item['message']->content) / 4);

            if ($estimatedTokens + $messageTokens > self::MAX_ESTIMATED_TOKENS) {
                continue;
            }

            $estimatedTokens += $messageTokens;
            $selected[] = $item;

            if (count($selected) >= self::MAX_CONTEXT_MESSAGES) {
                break;
            }
        }

        // Re-sort by original chronological order
        usort($selected, fn ($a, $b) => $a['index'] <=> $b['index']);

        $history = array_map(fn ($item) => [
            'role' => $item['message']->role,
            'content' => $item['message']->content,
        ], $selected);

        // Prepend pinned snapshot as context floor (if exists).
        // Cap at 4000 chars to bound attacker-influenced snapshot content.
        if ($snapshot !== null) {
            array_unshift($history, [
                'role' => 'system',
                'content' => mb_substr($snapshot->content ?? '', 0, 4000),
            ]);
        }

        return $history;
    }

    /**
     * Trigger compaction if the conversation exceeds the threshold and no recent
     * snapshot covers the current message count.
     */
    private function maybeCompact(AssistantConversation $conversation): void
    {
        $threshold = config('context_compaction.compaction_message_threshold', self::COMPACTION_THRESHOLD);

        $activeCount = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->where(function ($q) {
                $q->whereNull('metadata->archived')
                    ->orWhere('metadata->archived', false);
            })
            ->count();

        if ($activeCount < $threshold) {
            return;
        }

        try {
            app(ConversationCompactor::class)->compact($conversation);
        } catch (\Throwable $e) {
            // Compaction is best-effort — never block the conversation
            Log::warning('ConversationManager: compaction failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Score a message based on information density and relevance.
     * Higher scores = more likely to be kept in context.
     */
    public function scoreMessage(AssistantMessage $message, int $index, int $total): float
    {
        $score = 0.0;

        // Recency bonus (0-3 points, linear scale)
        $recencyRatio = $total > 1 ? $index / ($total - 1) : 1.0;
        $score += 3.0 * $recencyRatio;

        // Tool call/result messages are high-value
        if (! empty($message->tool_calls)) {
            $score += 2.0;
        }
        if (! empty($message->tool_results)) {
            $score += 2.0;
        }

        // Length penalty for very long messages
        $length = mb_strlen($message->content);
        if ($length > 2000) {
            $score -= 1.0;
        }
        if ($length > 5000) {
            $score -= 1.0;
        }

        // First 3 and last 3 messages always high priority (context anchoring)
        if ($index < 3 || $index >= $total - 3) {
            $score += 3.0;
        }

        // Pinned messages via metadata
        /** @var array|null $metadata */
        $metadata = $message->metadata;
        if (! empty($metadata['pinned'])) {
            $score += 5.0;
        }

        return $score;
    }

    /**
     * Get recent conversations for the sidebar list.
     *
     * @return Collection<AssistantConversation>
     */
    public function getRecentConversations(string $userId, int $limit = 20): Collection
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
