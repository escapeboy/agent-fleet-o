<?php

namespace App\Domain\Chatbot\Listeners;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Barsy\Events\ChatMessageCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ExtractChatMemoriesListener implements ShouldQueue
{
    public string $queue = 'metrics';

    public int $timeout = 90;

    public int $tries = 2;

    /** @var int[] */
    public array $backoff = [30];

    private const EXTRACT_MODEL = 'gpt-4o-mini';

    private const MIN_CONFIDENCE = 0.6;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a memory extractor for a customer support chatbot about the Barsy POS/ERP platform.

Analyze this chat exchange and extract durable, reusable facts that will improve future responses.

Extract ONLY facts that:
- Correct or clarify existing Barsy documentation
- Reveal undocumented features, settings, or workflows
- Capture domain-specific terminology or business rules
- Identify common user confusion points or FAQ patterns

DO NOT extract:
- Task-specific details (e.g., "user asked about invoices")
- Generic conversational patterns
- Information already obvious from the question alone
- Facts with low confidence or speculation

Return ONLY valid JSON (no markdown fences):
{
  "facts": [
    {
      "fact": "concise, durable statement",
      "confidence": 0.85,
      "category": "knowledge",
      "tags": ["domain", "pattern"]
    }
  ]
}

If no durable facts are found, return: {"facts": []}

Confidence: 0.0 = speculative, 1.0 = clearly demonstrated.
Category must be exactly one of: preference, knowledge, context, behavior, goal
Tags must be one or more of: capability, constraint, preference, pattern, domain, tooling
PROMPT;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
        private readonly StoreMemoryAction $storeMemory,
    ) {}

    public function handle(ChatMessageCompleted $event): void
    {
        if (! config('memory.enabled', true)) {
            return;
        }

        // Skip very short exchanges unlikely to contain durable facts
        if (mb_strlen($event->userMessage) < 20 || mb_strlen($event->assistantResponse) < 50) {
            return;
        }

        $chatbot = $event->chatbotId ? Chatbot::find($event->chatbotId) : null;
        $agentId = $chatbot?->agent_id ?? 'barsy-chatbot';
        $teamId = $chatbot?->team_id;

        $team = $teamId ? Team::find($teamId) : Team::first();
        if (! $team) {
            return;
        }

        try {
            $resolved = $this->providerResolver->resolve(team: $team);

            $prompt = "## User Message\n{$event->userMessage}\n\n## Assistant Response\n{$event->assistantResponse}";

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: self::EXTRACT_MODEL,
                systemPrompt: self::SYSTEM_PROMPT,
                userPrompt: $prompt,
                maxTokens: 512,
                teamId: $team->id,
                purpose: 'barsy.chat.memory.extract',
                temperature: 0.1,
            ));

            $result = json_decode($response->content, true);

            if (! is_array($result) || ! isset($result['facts']) || ! is_array($result['facts'])) {
                Log::warning('ExtractChatMemoriesListener: invalid response format', [
                    'conversation_id' => $event->conversationId,
                    'content' => substr($response->content, 0, 200),
                ]);

                return;
            }

            $roleTags = ["barsy:{$event->role}"];

            $stored = 0;
            foreach ($result['facts'] as $item) {
                $fact = trim($item['fact'] ?? '');
                $confidence = (float) ($item['confidence'] ?? 0.0);
                $llmTags = array_values(array_filter((array) ($item['tags'] ?? []), 'is_string'));
                $categoryValue = $item['category'] ?? null;
                $category = $categoryValue ? MemoryCategory::tryFrom($categoryValue) : null;

                if ($fact === '' || $confidence < self::MIN_CONFIDENCE) {
                    continue;
                }

                $tags = array_values(array_unique([...$llmTags, ...$roleTags]));

                $this->storeMemory->execute(
                    teamId: $team->id,
                    agentId: $agentId,
                    content: $fact,
                    sourceType: 'barsy_chat',
                    sourceId: $event->conversationId,
                    metadata: [
                        'extracted_at' => now()->toIso8601String(),
                        'role' => $event->role,
                        'chatbot_id' => $event->chatbotId,
                    ],
                    confidence: $confidence,
                    tags: $tags,
                    tier: MemoryTier::Proposed,
                    proposedBy: "barsy:{$event->role}",
                    category: $category,
                );

                $stored++;
            }

            if ($stored > 0) {
                Log::info('ExtractChatMemoriesListener: facts extracted', [
                    'conversation_id' => $event->conversationId,
                    'chatbot_id' => $event->chatbotId,
                    'role' => $event->role,
                    'agent_id' => $agentId,
                    'facts_stored' => $stored,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ExtractChatMemoriesListener: extraction failed', [
                'conversation_id' => $event->conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
