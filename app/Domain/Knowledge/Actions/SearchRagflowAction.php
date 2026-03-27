<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Infrastructure\RAGFlow\Exceptions\RAGFlowException;
use App\Infrastructure\RAGFlow\RAGFlowClient;
use Illuminate\Support\Facades\Log;

class SearchRagflowAction
{
    public function __construct(
        private readonly RAGFlowClient $client,
    ) {}

    /**
     * Retrieve relevant chunks from RAGFlow with hybrid BM25+vector search.
     *
     * Maps RAGFlowChunk objects to the standard search result format used
     * across the knowledge domain so callers don't need to know the source.
     *
     * @param  array{top_k?: int, use_kg?: bool, similarity_threshold?: float, vector_similarity_weight?: float}  $options
     * @return array<array{content: string, source: string, score: float, positions: array|null, image_path: string|null}>
     */
    public function execute(
        KnowledgeBase $knowledgeBase,
        string $query,
        array $options = [],
    ): array {
        $chunks = $this->client->retrieve(
            datasetId: $knowledgeBase->ragflow_dataset_id,
            query: $query,
            options: $options,
        );

        return array_map(static fn ($chunk) => [
            'content' => $chunk->content,
            'source' => $chunk->documentName,
            'score' => $chunk->similarity,
            'positions' => $chunk->positions,
            'image_path' => $chunk->imagePath,
        ], $chunks);
    }

    /**
     * Attempt RAGFlow retrieval; fall back to empty array on failure.
     *
     * Callers that need a graceful fallback (e.g. SearchKnowledgeAction) use
     * this variant so a RAGFlow outage never breaks agent execution.
     */
    public function executeWithFallback(KnowledgeBase $knowledgeBase, string $query, array $options = []): ?array
    {
        try {
            return $this->execute($knowledgeBase, $query, $options);
        } catch (RAGFlowException $e) {
            Log::warning('ragflow_fallback: falling back to pgvector', [
                'knowledge_base_id' => $knowledgeBase->id,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ]);

            return null;
        }
    }
}
