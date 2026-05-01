<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Support\Facades\Log;

class ContextualChunkEnricherAction
{
    private const CONTEXT_MODEL = 'claude-haiku-4-5';

    private const CONTEXT_PROMPT = <<<'PROMPT'
<document>
%s
</document>

<chunk>
%s
</chunk>

Give a short succinct context (max 2 sentences) to situate this chunk within the document. Output the context only, no preamble.
PROMPT;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Generate LLM context for a chunk and re-embed with context prepended.
     *
     * Follows Anthropic's Contextual Retrieval technique: a small LLM call
     * generates situating context which is prepended to the chunk before
     * embedding. Improves retrieval for chunks that are ambiguous in isolation.
     */
    public function execute(Memory $memory, string $documentContext): void
    {
        if (! config('memory.contextual_rag.enabled', false)) {
            return;
        }

        if ($memory->chunk_context !== null) {
            return;
        }

        try {
            $truncatedDoc = mb_substr($documentContext, 0, 8000);

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: 'anthropic',
                model: self::CONTEXT_MODEL,
                systemPrompt: 'You are a document analyst. Generate precise, informative chunk context.',
                userPrompt: sprintf(self::CONTEXT_PROMPT, $truncatedDoc, $memory->content),
                maxTokens: 128,
                teamId: $memory->team_id,
                userId: Team::ownerIdFor($memory->team_id),
                purpose: 'memory.contextual_rag',
                temperature: 0.0,
            ));

            $context = trim($response->content);

            if ($context === '') {
                return;
            }

            // Re-embed with context prepended so retrieval benefits from the enriched text.
            $enrichedContent = $context."\n\n".$memory->content;

            $embeddingService = new EmbeddingService(
                provider: config('memory.embedding_provider', 'openai'),
                model: config('memory.embedding_model', 'text-embedding-3-small'),
            );

            $vector = $embeddingService->embedForTeam($enrichedContent, $memory->team_id);

            $updates = ['chunk_context' => $context];

            if ($vector !== null) {
                $updates['embedding'] = $embeddingService->formatForPgvector($vector);
            }

            $memory->update($updates);
        } catch (\Throwable $e) {
            Log::debug('ContextualChunkEnricher: failed', [
                'memory_id' => $memory->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
