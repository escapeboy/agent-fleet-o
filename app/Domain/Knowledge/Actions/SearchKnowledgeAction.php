<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Services\KnowledgeBaseRAGFactory;

class SearchKnowledgeAction
{
    public function __construct(
        private readonly KnowledgeBaseRAGFactory $factory,
        private readonly SearchRagflowAction $ragflowSearch,
    ) {}

    /**
     * Semantic search across a knowledge base.
     *
     * Routes to RAGFlow (hybrid BM25+vector) when the knowledge base has
     * RAGFlow enabled and a dataset synced. Falls back to pgvector on failure.
     *
     * @param  array{top_k?: int, use_kg?: bool, similarity_threshold?: float}  $options
     * @return array<array{content: string, source: string, score: float}>
     */
    public function execute(
        string $knowledgeBaseId,
        string $query,
        int $topK = 5,
        array $options = [],
    ): array {
        $kb = KnowledgeBase::find($knowledgeBaseId);

        if ($kb && $kb->isRagflowReady()) {
            $results = $this->ragflowSearch->executeWithFallback(
                $kb,
                $query,
                array_merge(['top_k' => $topK], $options),
            );

            if ($results !== null) {
                return $results;
            }
        }

        return $this->factory->search($knowledgeBaseId, $query, $topK);
    }
}
