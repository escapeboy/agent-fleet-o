<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Services\KnowledgeBaseRAGFactory;

class SearchKnowledgeAction
{
    public function __construct(
        private readonly KnowledgeBaseRAGFactory $factory,
    ) {}

    /**
     * Semantic search across a knowledge base.
     *
     * @return array<array{content: string, source: string, score: float}>
     */
    public function execute(
        string $knowledgeBaseId,
        string $query,
        int $topK = 5,
    ): array {
        return $this->factory->search($knowledgeBaseId, $query, $topK);
    }
}
