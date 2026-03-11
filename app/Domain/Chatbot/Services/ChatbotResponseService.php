<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotKbChunk;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

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

        // RAG retrieval — only if chatbot has indexed knowledge sources
        $ragChunks = [];
        $bestRagScore = 0.0;
        if ($chatbot->knowledgeSources()->where('status', 'ready')->exists()) {
            $ragChunks = $this->retrieveRelevantChunks($chatbot, $userText);
            $bestRagScore = ! empty($ragChunks) ? (float) ($ragChunks[0]['similarity'] ?? 0.0) : 0.0;
        }

        // Prepare input for the agent
        $agentInput = [
            'task' => $userText,
            'context' => $this->formatContextForAgent($contextMessages, $userText, $ragChunks),
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

        // Composite confidence: LLM execution success + RAG best similarity score
        $confidence = $this->estimateConfidence($result, $bestRagScore);

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
        $ragSourceMeta = ! empty($ragChunks)
            ? array_map(fn ($c) => ['chunk_id' => $c['id'], 'similarity' => $c['similarity']], $ragChunks)
            : null;

        $assistantMsg = ChatbotMessage::create([
            'session_id' => $session->id,
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'role' => 'assistant',
            'content' => $rawReply,
            'confidence' => $confidence,
            'latency_ms' => $latencyMs,
            'metadata' => $ragSourceMeta ? ['sources' => $ragSourceMeta] : null,
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

    private function formatContextForAgent(array $contextMessages, string $currentMessage, array $ragChunks = []): string
    {
        $parts = [];

        if (! empty($ragChunks)) {
            $docs = collect($ragChunks)
                ->map(fn ($c) => "- {$c['content']}")
                ->join("\n");
            $parts[] = "Reference documents:\n{$docs}";
        }

        if (! empty($contextMessages)) {
            $history = collect($contextMessages)
                ->map(fn ($m) => ucfirst($m['role']).': '.$m['content'])
                ->join("\n");
            $parts[] = "Conversation history:\n{$history}";
        }

        $parts[] = "User: {$currentMessage}";

        return implode("\n\n", $parts);
    }

    /**
     * Retrieve the top relevant KB chunks for a query using pgvector cosine similarity.
     *
     * @return array<array{id: string, content: string, similarity: float}>
     */
    private function retrieveRelevantChunks(Chatbot $chatbot, string $query, float $threshold = 0.72, int $topK = 5): array
    {
        try {
            $response = Prism::embeddings()
                ->using('openai', 'text-embedding-3-small')
                ->fromInput($query)
                ->asEmbeddings();

            $vector = $response->embeddings[0]->embedding;
            $embeddingStr = '['.implode(',', $vector).']';

            $rows = DB::select(
                "SELECT id, content, 1 - (embedding <=> ?::vector) AS similarity
                 FROM chatbot_kb_chunks
                 WHERE chatbot_id = ?
                   AND team_id = ?
                   AND embedding IS NOT NULL
                   AND 1 - (embedding <=> ?::vector) >= ?
                 ORDER BY embedding <=> ?::vector
                 LIMIT ?",
                [$embeddingStr, $chatbot->id, $chatbot->team_id, $embeddingStr, $threshold, $embeddingStr, $topK]
            );

            return array_map(fn ($row) => [
                'id' => $row->id,
                'content' => $row->content,
                'similarity' => (float) $row->similarity,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning('ChatbotResponseService: RAG retrieval failed', [
                'chatbot_id' => $chatbot->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Composite confidence: LLM execution quality + RAG retrieval signal.
     */
    private function estimateConfidence(array $executionResult, float $ragScore = 0.0): float
    {
        if (isset($executionResult['output']['confidence'])) {
            $llmScore = (float) $executionResult['output']['confidence'];
        } elseif (! empty($executionResult['output'])) {
            $llmScore = 0.85;
        } else {
            $llmScore = 0.30;
        }

        if ($ragScore > 0.0) {
            // Weighted average: 60% LLM score, 40% RAG score
            return round(($llmScore * 0.6) + ($ragScore * 0.4), 4);
        }

        return $llmScore;
    }
}
