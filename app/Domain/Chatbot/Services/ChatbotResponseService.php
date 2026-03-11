<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;
use Illuminate\Support\Facades\Cache;

class ChatbotResponseService
{
    // Redis TTL for context cache: 30 minutes
    private const CONTEXT_TTL = 1800;

    // How many prior messages to include as context
    private const CONTEXT_WINDOW = 10;

    public function __construct(
        private readonly ExecuteAgentAction $executeAgent,
    ) {}

    /**
     * Process a user message and return the assistant response.
     *
     * @return array{message: ChatbotMessage, escalated: bool, reply: string|null}
     */
    public function handle(
        Chatbot $chatbot,
        ChatbotSession $session,
        string $userText,
        string $actorUserId,
    ): array {
        $startedAt = microtime(true);

        // Persist the user message
        $userMsg = ChatbotMessage::create([
            'session_id' => $session->id,
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'role' => 'user',
            'content' => $userText,
        ]);

        // Build conversation context from Redis or DB
        $contextMessages = $this->loadContext($session, $chatbot);

        // Prepare input for the agent
        $agentInput = [
            'task' => $userText,
            'context' => $this->formatContextForAgent($contextMessages, $userText),
        ];

        // Execute the backing agent
        $result = $this->executeAgent->execute(
            agent: $chatbot->agent,
            input: $agentInput,
            teamId: $chatbot->team_id,
            userId: $actorUserId,
        );

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);
        $rawReply = $result['output']['content'] ?? ($result['output']['result'] ?? '');

        // Simple confidence scoring: ask the LLM to rate its own confidence
        // Phase 3 will add RAG retrieval score as a second signal
        $confidence = $this->estimateConfidence($result);

        $needsEscalation = $chatbot->human_escalation_enabled
            && $confidence < (float) $chatbot->confidence_threshold;

        if ($needsEscalation) {
            $assistantMsg = ChatbotMessage::create([
                'session_id' => $session->id,
                'chatbot_id' => $chatbot->id,
                'team_id' => $chatbot->team_id,
                'role' => 'assistant',
                'content' => null, // filled after approval
                'draft_content' => $rawReply,
                'confidence' => $confidence,
                'latency_ms' => $latencyMs,
                'was_escalated' => true,
            ]);

            // Create approval request directly (chatbot-specific, not experiment-bound)
            ApprovalRequest::withoutGlobalScopes()->create([
                'team_id' => $chatbot->team_id,
                'chatbot_message_id' => $assistantMsg->id,
                'status' => ApprovalStatus::Pending,
                'context' => [
                    'type' => 'chatbot_response',
                    'chatbot_id' => $chatbot->id,
                    'chatbot_name' => $chatbot->name,
                    'session_id' => $session->id,
                    'user_message' => $userText,
                    'draft_response' => $rawReply,
                    'confidence' => $confidence,
                    'recent_messages' => array_slice($contextMessages, -5),
                ],
                'expires_at' => now()->addHours(48),
            ]);

            $this->invalidateContextCache($session);

            return [
                'message' => $assistantMsg,
                'escalated' => true,
                'reply' => null, // placeholder delivered to user
            ];
        }

        // Normal path: persist and return
        $assistantMsg = ChatbotMessage::create([
            'session_id' => $session->id,
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'role' => 'assistant',
            'content' => $rawReply,
            'confidence' => $confidence,
            'latency_ms' => $latencyMs,
        ]);

        $this->updateContextCache($session, $chatbot, $userText, $rawReply);

        // Update session stats
        $session->increment('message_count', 2);
        $session->update(['last_activity_at' => now()]);

        return [
            'message' => $assistantMsg,
            'escalated' => false,
            'reply' => $rawReply,
        ];
    }

    private function loadContext(ChatbotSession $session, Chatbot $chatbot): array
    {
        $cacheKey = "chatbot:session:{$session->id}:context";
        $cached = Cache::store('redis')->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Hydrate from DB (last N messages)
        $messages = ChatbotMessage::where('session_id', $session->id)
            ->whereNotNull('content')
            ->orderByDesc('created_at')
            ->limit(self::CONTEXT_WINDOW)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->toArray();

        Cache::store('redis')->put($cacheKey, $messages, self::CONTEXT_TTL);

        return $messages;
    }

    private function updateContextCache(ChatbotSession $session, Chatbot $chatbot, string $userText, string $reply): void
    {
        $cacheKey = "chatbot:session:{$session->id}:context";
        $messages = Cache::store('redis')->get($cacheKey, []);
        $messages[] = ['role' => 'user', 'content' => $userText];
        $messages[] = ['role' => 'assistant', 'content' => $reply];

        // Keep only the last CONTEXT_WINDOW messages
        if (count($messages) > self::CONTEXT_WINDOW) {
            $messages = array_slice($messages, -self::CONTEXT_WINDOW);
        }

        Cache::store('redis')->put($cacheKey, $messages, self::CONTEXT_TTL);
    }

    private function invalidateContextCache(ChatbotSession $session): void
    {
        Cache::store('redis')->forget("chatbot:session:{$session->id}:context");
    }

    private function formatContextForAgent(array $contextMessages, string $currentMessage): string
    {
        if (empty($contextMessages)) {
            return $currentMessage;
        }

        $history = collect($contextMessages)
            ->map(fn ($m) => ucfirst($m['role']).': '.$m['content'])
            ->join("\n");

        return "Conversation history:\n{$history}\n\nUser: {$currentMessage}";
    }

    private function estimateConfidence(array $executionResult): float
    {
        // Check if execution returned a structured confidence
        if (isset($executionResult['output']['confidence'])) {
            return (float) $executionResult['output']['confidence'];
        }

        // Default: execution succeeded → high confidence
        // Phase 3 will add RAG retrieval score combination
        if (! empty($executionResult['output'])) {
            return 0.85;
        }

        return 0.30;
    }
}
