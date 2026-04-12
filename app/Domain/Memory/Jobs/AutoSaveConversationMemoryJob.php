<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Enums\MemoryTier;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AutoSaveConversationMemoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    private const MODEL = 'claude-haiku-4-5-20251001';

    private const SYSTEM_PROMPT = 'You are a memory extractor. Return ONLY valid JSON array, no explanation.';

    private const USER_PROMPT = <<<'PROMPT'
Extract memorable facts from this conversation. Return a JSON array of up to 5 items, each with:
- "content": the fact/decision/insight to remember (1-2 sentences)
- "category": one of facts|events|discoveries|preferences|advice
- "topic": snake_case slug for the context (e.g. "auth_migration", "deployment_workflow")
- "importance": 0.1–1.0

Only include items worth remembering long-term: decisions, insights, preferences, breakthroughs.
Skip small-talk, greetings, simple acknowledgements, and purely technical clarifications.
Return [] if nothing is worth remembering.

Conversation:
%s
PROMPT;

    public function __construct(
        private readonly string $conversationId,
        private readonly string $teamId,
        private readonly string $userId,
    ) {
        $this->onQueue('default');
    }

    public function handle(AiGatewayInterface $gateway, StoreMemoryAction $store): void
    {
        $conversation = AssistantConversation::withoutGlobalScopes()
            ->where('id', $this->conversationId)
            ->where('team_id', $this->teamId)
            ->first();

        if (! $conversation) {
            return;
        }

        // Fetch the last 15 messages
        $messages = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest()
            ->limit(15)
            ->get()
            ->reverse()
            ->values();

        if ($messages->count() < 2) {
            return;
        }

        $snippet = $messages->map(fn ($m) => strtoupper($m->role).': '.mb_substr($m->content ?? '', 0, 300))
            ->implode("\n\n");

        try {
            $response = $gateway->complete(new AiRequestDTO(
                provider: 'anthropic',
                model: self::MODEL,
                systemPrompt: self::SYSTEM_PROMPT,
                userPrompt: sprintf(self::USER_PROMPT, $snippet),
                maxTokens: 512,
                teamId: $this->teamId,
                userId: $this->userId,
                purpose: 'memory.auto_save',
                temperature: 0.1,
            ));

            $items = json_decode(trim($response->content), true);

            if (! is_array($items) || empty($items)) {
                return;
            }

            $stored = 0;
            foreach ($items as $item) {
                $content = trim($item['content'] ?? '');
                if ($content === '') {
                    continue;
                }

                $topic = isset($item['topic']) && is_string($item['topic'])
                    ? mb_substr(preg_replace('/[^a-z0-9_]/', '_', strtolower($item['topic'])) ?? '', 0, 50)
                    : null;

                $category = isset($item['category'])
                    ? MemoryCategory::tryFrom($item['category'])
                    : null;

                $importance = isset($item['importance']) ? (float) $item['importance'] : 0.6;
                $importance = max(0.1, min(1.0, $importance));

                $store->execute(
                    teamId: $this->teamId,
                    agentId: null,
                    content: $content,
                    sourceType: 'assistant_conversation',
                    sourceId: $this->conversationId,
                    importance: $importance,
                    tier: MemoryTier::Working,
                    category: $category,
                    topic: $topic,
                );

                $stored++;
            }

            Log::info('AutoSaveConversationMemoryJob: saved memories', [
                'conversation_id' => $this->conversationId,
                'items_extracted' => count($items),
                'items_stored' => $stored,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AutoSaveConversationMemoryJob: failed', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
