<?php

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Models\KgEdge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class SearchKgFactsAction
{
    /**
     * Semantic search over knowledge graph facts.
     *
     * @return Collection<int, KgEdge>
     */
    public function execute(
        string $teamId,
        string $query,
        ?string $relationType = null,
        ?string $entityType = null,
        bool $includeHistory = false,
        float $threshold = 0.7,
        int $limit = 10,
    ): Collection {
        try {
            $queryEmbedding = $this->generateEmbedding($query);

            $builder = KgEdge::withoutGlobalScopes()
                ->select('kg_edges.*')
                ->selectRaw('1 - (fact_embedding <=> ?) AS similarity', [$queryEmbedding])
                ->where('team_id', $teamId)
                ->whereNotNull('fact_embedding')
                ->havingRaw('1 - (fact_embedding <=> ?) >= ?', [$queryEmbedding, $threshold])
                ->with(['sourceEntity', 'targetEntity'])
                ->orderByDesc('similarity');

            if (! $includeHistory) {
                $builder->whereNull('invalid_at');
            }

            if ($relationType) {
                $builder->where('relation_type', $relationType);
            }

            if ($entityType) {
                $builder->whereHas('sourceEntity', fn ($q) => $q->where('type', $entityType));
            }

            return $builder->limit($limit)->get();
        } catch (\Throwable $e) {
            Log::warning('SearchKgFactsAction: Search failed', [
                'team_id' => $teamId,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    private function generateEmbedding(string $text): string
    {
        $model = config('memory.embedding_model', 'text-embedding-3-small');

        $response = Prism::embeddings()
            ->using('openai', $model)
            ->fromInput($text)
            ->asEmbeddings();

        $vector = $response->embeddings[0]->embedding;

        return '['.implode(',', $vector).']';
    }
}
