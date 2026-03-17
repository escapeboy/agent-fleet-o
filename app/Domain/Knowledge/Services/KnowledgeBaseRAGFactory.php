<?php

namespace App\Domain\Knowledge\Services;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\NeuronPrismProvider;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\RAG;

/**
 * Builds a NeuronAI RAG instance wired to FleetQ's PrismAiGateway.
 */
class KnowledgeBaseRAGFactory
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Create a RAG agent for a specific knowledge base.
     *
     * @param  string  $provider  e.g. 'anthropic'
     * @param  string  $model     e.g. 'claude-haiku-4-5'
     */
    public function make(
        string $knowledgeBaseId,
        string $provider,
        string $model,
        ?string $teamId = null,
        ?string $agentId = null,
        int $topK = 5,
        string $purpose = 'neuron.rag',
    ): RAG {
        $neuronProvider = new NeuronPrismProvider(
            gateway: $this->gateway,
            provider: $provider,
            model: $model,
            teamId: $teamId,
            agentId: $agentId,
            purpose: $purpose,
        );

        $embeddingsProvider = new PrismEmbeddingsProvider;
        $vectorStore = new PgVectorKnowledgeStore($knowledgeBaseId, $topK);

        return new class($neuronProvider, $embeddingsProvider, $vectorStore) extends RAG {
            public function __construct(
                private readonly \NeuronAI\Providers\AIProviderInterface $neuronProvider,
                private readonly PrismEmbeddingsProvider $embeds,
                private readonly PgVectorKnowledgeStore $store,
            ) {}

            protected function provider(): \NeuronAI\Providers\AIProviderInterface
            {
                return $this->neuronProvider;
            }

            protected function embeddings(): \NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface
            {
                return $this->embeds;
            }

            protected function vectorStore(): \NeuronAI\RAG\VectorStore\VectorStoreInterface
            {
                return $this->store;
            }
        };
    }

    /**
     * Convenience: query the knowledge base and return top-K relevant text chunks.
     *
     * @return array<array{content: string, source: string, score: float}>
     */
    public function search(
        string $knowledgeBaseId,
        string $query,
        int $topK = 5,
    ): array {
        $embedder = new PrismEmbeddingsProvider;
        $store = new PgVectorKnowledgeStore($knowledgeBaseId, $topK);

        $embedding = $embedder->embedText($query);
        $docs = $store->similaritySearch($embedding);

        return array_map(fn ($doc) => [
            'content' => $doc->content,
            'source' => $doc->sourceName,
            'score' => $doc->score,
        ], is_array($docs) ? $docs : iterator_to_array($docs));
    }
}
