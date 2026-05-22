<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Jobs\ExecuteChatbotWorkflowJob;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Barsy\Services\EmbeddingServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatbotResponseService
{
    // Redis TTL for context cache: 30 minutes
    private const CONTEXT_TTL = 1800;

    // How many prior messages to include as context
    private const CONTEXT_WINDOW = 10;

    public function __construct(
        private readonly ExecuteAgentAction $executeAgent,
        private readonly EmbeddingServiceInterface $embedding,
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
        ChatbotMessage::create([
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
        if ($chatbot->knowledgeSources()->where('status', 'ready')->where('is_enabled', true)->exists()) {
            $ragChunks = $this->retrieveRelevantChunks($chatbot, $userText);
            $bestRagScore = ! empty($ragChunks) ? (float) ($ragChunks[0]['similarity'] ?? 0.0) : 0.0;
        }

        // Workflow delegation — if a workflow is linked, delegate asynchronously
        if ($chatbot->workflow_id) {
            return $this->handleViaWorkflow(
                chatbot: $chatbot,
                session: $session,
                userText: $userText,
                actorUserId: $actorUserId,
                startedAt: $startedAt,
                contextMessages: $contextMessages,
                ragChunks: $ragChunks,
            );
        }

        // Prepare input for the agent
        $agentInput = [
            'task' => $userText,
            'context' => $this->formatContextForAgent($chatbot, $contextMessages, $userText, $ragChunks),
        ];

        // Execute the backing agent
        $result = $this->executeAgent->execute(
            agent: $chatbot->agent,
            input: $agentInput,
            teamId: $chatbot->team_id,
            userId: $actorUserId,
        );

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);
        $rawReply = $result['output']['content'] ?? $result['output']['result'] ?? $result['output']['text'] ?? '';

        // Composite confidence: LLM execution success + RAG best similarity score
        $confidence = $this->estimateConfidence($result, $bestRagScore);

        // Append fallback message when low confidence and escalation is disabled
        if (
            ! $chatbot->human_escalation_enabled
            && $confidence < (float) $chatbot->confidence_threshold
            && $chatbot->fallback_message
        ) {
            $rawReply = $rawReply
                ? $rawReply."\n\n".$chatbot->fallback_message
                : $chatbot->fallback_message;
        }

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

            $timeoutHours = $chatbot->approval_timeout_hours ?? 48;

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
                'expires_at' => now()->addHours($timeoutHours),
            ]);

            $this->invalidateContextCache($session);

            return [
                'message' => $assistantMsg,
                'escalated' => true,
                'reply' => null,
            ];
        }

        // Normal path: build enriched source metadata and persist
        $ragSourceMeta = $this->buildRagSourceMeta($chatbot, $ragChunks);

        $assistantMsg = ChatbotMessage::create([
            'session_id' => $session->id,
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'role' => 'assistant',
            'content' => $rawReply,
            'confidence' => $confidence,
            'latency_ms' => $latencyMs,
            'metadata' => $ragSourceMeta ? ['sources' => $ragSourceMeta] : [],
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

    /**
     * Stream a chatbot response via SSE (token-by-token).
     *
     * Calls $onChunk(string $text) for each LLM token.
     * Returns the final ChatbotMessage after streaming completes.
     */
    public function handleStream(
        Chatbot $chatbot,
        ChatbotSession $session,
        string $userText,
        string $actorUserId,
        callable $onChunk,
    ): ChatbotMessage {
        $startedAt = microtime(true);

        ChatbotMessage::create([
            'session_id' => $session->id,
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'role' => 'user',
            'content' => $userText,
        ]);

        $contextMessages = $this->loadContext($session, $chatbot);

        $ragChunks = [];
        $bestRagScore = 0.0;
        if ($chatbot->knowledgeSources()->where('status', 'ready')->where('is_enabled', true)->exists()) {
            $ragChunks = $this->retrieveRelevantChunks($chatbot, $userText);
            $bestRagScore = ! empty($ragChunks) ? (float) ($ragChunks[0]['similarity'] ?? 0.0) : 0.0;
        }

        $context = $this->formatContextForAgent($chatbot, $contextMessages, $userText, $ragChunks);
        $systemPrompt = $this->buildChatbotSystemPrompt($chatbot);

        $gateway = app(AiGatewayInterface::class);

        $request = new AiRequestDTO(
            provider: $chatbot->agent?->provider->value ?? 'openai',
            model: $chatbot->agent->model ?? 'gpt-4o-mini',
            systemPrompt: $systemPrompt,
            userPrompt: $context,
            maxTokens: 2048,
            userId: $actorUserId,
            teamId: $chatbot->team_id,
            purpose: 'chatbot_stream',
            temperature: 0.7,
        );

        $response = $gateway->stream($request, $onChunk);

        $rawReply = $response->content ?? '';
        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);
        $confidence = $bestRagScore > 0.0
            ? round((0.85 * 0.6) + ($bestRagScore * 0.4), 4)
            : ($rawReply ? 0.85 : 0.30);

        // Append fallback if low confidence
        if (
            ! $chatbot->human_escalation_enabled
            && $confidence < (float) $chatbot->confidence_threshold
            && $chatbot->fallback_message
        ) {
            $suffix = "\n\n".$chatbot->fallback_message;
            $rawReply .= $suffix;
            $onChunk($suffix);
        }

        $ragSourceMeta = $this->buildRagSourceMeta($chatbot, $ragChunks);

        $assistantMsg = ChatbotMessage::create([
            'session_id' => $session->id,
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'role' => 'assistant',
            'content' => $rawReply,
            'confidence' => $confidence,
            'latency_ms' => $latencyMs,
            'metadata' => $ragSourceMeta ? ['sources' => $ragSourceMeta] : [],
        ]);

        $this->updateContextCache($session, $chatbot, $userText, $rawReply);
        $session->increment('message_count', 2);
        $session->update(['last_activity_at' => now()]);

        return $assistantMsg;
    }

    /**
     * Build system prompt from agent role/goal/backstory.
     */
    private function buildChatbotSystemPrompt(Chatbot $chatbot): string
    {
        $agent = $chatbot->agent;
        if (! $agent) {
            return 'You are a helpful assistant.';
        }

        $parts = ["You are an AI assistant named \"{$agent->name}\"."];

        if ($agent->role) {
            $parts[] = "Your role: {$agent->role}";
        }
        if ($agent->goal) {
            $parts[] = "Your goal: {$agent->goal}";
        }
        if ($agent->backstory) {
            $parts[] = $agent->backstory;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Delegate message handling to an async workflow execution.
     * Returns a pending placeholder — result delivered when workflow completes.
     *
     * @return array{message: ChatbotMessage, escalated: bool, reply: string|null}
     */
    private function handleViaWorkflow(
        Chatbot $chatbot,
        ChatbotSession $session,
        string $userText,
        string $actorUserId,
        float $startedAt,
        array $contextMessages,
        array $ragChunks,
    ): array {
        $assistantMsg = ChatbotMessage::create([
            'session_id' => $session->id,
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'role' => 'assistant',
            'content' => null,
            'was_escalated' => true,
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        $context = $this->formatContextForAgent($chatbot, $contextMessages, $userText, $ragChunks);

        ExecuteChatbotWorkflowJob::dispatch(
            chatbotId: $chatbot->id,
            sessionId: $session->id,
            messageId: $assistantMsg->id,
            workflowId: $chatbot->workflow_id,
            userText: $userText,
            context: $context,
            actorUserId: $actorUserId,
            teamId: $chatbot->team_id,
        )->onQueue('ai-calls');

        $this->invalidateContextCache($session);

        return [
            'message' => $assistantMsg,
            'escalated' => true,
            'reply' => null,
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

    private function formatContextForAgent(Chatbot $chatbot, array $contextMessages, string $currentMessage, array $ragChunks = []): string
    {
        $parts = [];

        if (! empty($ragChunks)) {
            $docs = collect($ragChunks)
                ->map(function ($c) use ($chatbot) {
                    $content = $c['content'];

                    // developer_assistant: wrap code-level chunks in fenced code blocks
                    if (
                        $chatbot->type === ChatbotType::DeveloperAssistant
                        && ($c['access_level'] ?? '') === 'code'
                    ) {
                        $content = "```\n{$content}\n```";
                    }

                    return "- {$content}";
                })
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
     * Build RAG source metadata array, enriched with source name/URL for support_assistant.
     */
    private function buildRagSourceMeta(Chatbot $chatbot, array $ragChunks): ?array
    {
        if (empty($ragChunks)) {
            return null;
        }

        if ($chatbot->type === ChatbotType::SupportAssistant) {
            $sourceIds = array_unique(array_column($ragChunks, 'source_id'));
            $sources = ChatbotKnowledgeSource::whereIn('id', $sourceIds)
                ->get(['id', 'name', 'source_url'])
                ->keyBy('id');

            return array_map(fn ($c) => [
                'chunk_id' => $c['id'],
                'similarity' => $c['similarity'],
                'source_name' => $sources[$c['source_id']]->name ?? null,
                'source_url' => $sources[$c['source_id']]->source_url ?? null,
            ], $ragChunks);
        }

        return array_map(fn ($c) => [
            'chunk_id' => $c['id'],
            'similarity' => $c['similarity'],
        ], $ragChunks);
    }

    /**
     * Returns the allowed access levels for RAG retrieval based on chatbot type.
     */
    private function allowedAccessLevels(Chatbot $chatbot): array
    {
        return match ($chatbot->type) {
            ChatbotType::HelpBot => ['public', 'key'],
            ChatbotType::DeveloperAssistant => ['internal', 'code'],
            default => ['public', 'key', 'representative', 'internal', 'code'],
        };
    }

    /**
     * Retrieve the top relevant KB chunks for a query using pgvector cosine similarity.
     *
     * @return array<array{id: string, content: string, similarity: float, access_level: string, source_id: string}>
     */
    private function retrieveRelevantChunks(Chatbot $chatbot, string $query, float $threshold = 0.5, int $topK = 5): array
    {
        try {
            $vector = $this->embedding->generate($query);
            $embeddingStr = '['.implode(',', $vector).']';

            $allowedLevels = $this->allowedAccessLevels($chatbot);
            $placeholders = implode(',', array_fill(0, count($allowedLevels), '?'));

            $params = [
                $embeddingStr,
                $chatbot->id,
                $chatbot->team_id,
                ...$allowedLevels,
                $embeddingStr,
                $threshold,
                $embeddingStr,
                $topK,
            ];

            $rows = DB::select(
                "SELECT ckc.id, ckc.content, ckc.access_level, ckc.source_id, 1 - (ckc.embedding <=> ?::vector) AS similarity
                 FROM chatbot_kb_chunks ckc
                 JOIN chatbot_knowledge_sources cks ON cks.id = ckc.source_id AND cks.is_enabled = true AND cks.deleted_at IS NULL
                 WHERE ckc.chatbot_id = ?
                   AND ckc.team_id = ?
                   AND ckc.embedding IS NOT NULL
                   AND ckc.access_level IN ({$placeholders})
                   AND 1 - (ckc.embedding <=> ?::vector) >= ?
                 ORDER BY ckc.embedding <=> ?::vector
                 LIMIT ?",
                $params,
            );

            return array_map(fn ($row) => [
                'id' => $row->id,
                'content' => $row->content,
                'similarity' => (float) $row->similarity,
                'access_level' => $row->access_level,
                'source_id' => $row->source_id,
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
