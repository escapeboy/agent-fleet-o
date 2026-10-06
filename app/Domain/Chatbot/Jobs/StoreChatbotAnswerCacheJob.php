<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Enums\KnowledgeSourceStatus;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Services\ChatbotAnswerCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes one answer to the semantic cache after the visitor already got it.
 * A bad entry is served to every later visitor, so anything doubtful is dropped.
 */
class StoreChatbotAnswerCacheJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  float[]  $vector
     * @param  list<array<string, mixed>>|null  $sources
     */
    public function __construct(
        public readonly string $chatbotId,
        public readonly int $generation,
        public readonly string $promptHash,
        public readonly string $question,
        public readonly array $vector,
        public readonly string $answer,
        public readonly ?array $sources = null,
        public readonly ?float $confidence = null,
        public readonly ?int $generationTokens = null,
        public readonly ?int $generationCostCredits = null,
    ) {}

    public function handle(ChatbotAnswerCache $cache): void
    {
        $chatbot = Chatbot::find($this->chatbotId);
        if (! $chatbot || ! $cache->isEnabled($chatbot)) {
            return;
        }

        // Knowledge or prompt changed while the answer was being generated.
        if ((int) $chatbot->answer_cache_generation !== $this->generation
            || $cache->promptHash($chatbot) !== $this->promptHash) {
            return;
        }

        // Re-indexing deletes chunks before inserting new ones: the answer may
        // come from a half-built knowledge base.
        if ($chatbot->knowledgeSources()
            ->whereIn('status', [KnowledgeSourceStatus::Pending->value, KnowledgeSourceStatus::Indexing->value])
            ->exists()) {
            return;
        }

        if (ChatbotAnswerCache::looksLikeNonAnswer($this->answer, $chatbot->fallback_message)
            || ChatbotAnswerCache::containsPersonalData($this->answer)
            || $cache->hasUngroundedLinks($chatbot, $this->answer, $this->sources)
            || ! $cache->passesStoreCheck($chatbot, $this->question, $this->answer)) {
            return;
        }

        $cache->store(
            chatbot: $chatbot,
            generation: $this->generation,
            promptHash: $this->promptHash,
            question: $this->question,
            vector: $this->vector,
            answer: $this->answer,
            sources: $this->sources,
            confidence: $this->confidence,
            generationTokens: $this->generationTokens,
            generationCostCredits: $this->generationCostCredits,
        );
    }
}
