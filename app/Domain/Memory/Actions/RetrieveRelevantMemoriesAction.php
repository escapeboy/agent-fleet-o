<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class RetrieveRelevantMemoriesAction
{
    /**
     * Retrieve top-K relevant memories using cosine similarity.
     *
     * @param  string  $scope  'agent' (default), 'team', or 'project'
     * @return Collection<int, Memory>
     */
    public function execute(
        string $agentId,
        string $query,
        ?string $projectId = null,
        ?int $topK = null,
        ?float $threshold = null,
        string $scope = 'agent',
        ?string $teamId = null,
        float $minConfidence = 0.3,
    ): Collection {
        if (! config('memory.enabled', true)) {
            return collect();
        }

        $topK = $topK ?? config('memory.top_k', 5);
        $threshold = $threshold ?? config('memory.similarity_threshold', 0.7);

        try {
            $queryEmbedding = $this->generateEmbedding($query);

            // Composite scoring weights from config
            $semanticWeight = config('memory.scoring.semantic_weight', 0.5);
            $recencyWeight = config('memory.scoring.recency_weight', 0.3);
            $importanceWeight = config('memory.scoring.importance_weight', 0.2);
            $halfLifeDays = config('memory.scoring.half_life_days', 7);

            // Composite score = semantic * similarity + recency * decay + importance * score
            // Recency decay: 0.5 ^ (age_days / half_life_days) using PostgreSQL POWER()
            $compositeScoreSql = <<<'SQL'
                (? * (1 - (embedding <=> ?))) +
                (? * POWER(0.5, EXTRACT(EPOCH FROM (NOW() - COALESCE(last_accessed_at, created_at))) / 86400.0 / ?)) +
                (? * COALESCE(importance, 0.5))
                AS composite_score
            SQL;

            $builder = Memory::withoutGlobalScopes()
                ->select('memories.*')
                ->selectRaw($compositeScoreSql, [
                    $semanticWeight, $queryEmbedding,
                    $recencyWeight, $halfLifeDays,
                    $importanceWeight,
                ])
                ->havingRaw('1 - (embedding <=> ?) >= ?', [$queryEmbedding, $threshold])
                ->where('confidence', '>=', $minConfidence)
                ->orderByDesc('composite_score');

            // Apply scope filtering
            match ($scope) {
                'team' => $builder->when($teamId, fn ($q) => $q->where('team_id', $teamId)),
                'project' => $builder->where(function ($q) use ($agentId, $projectId) {
                    $q->where('agent_id', $agentId);
                    if ($projectId) {
                        $q->orWhere(function ($sub) use ($projectId) {
                            $sub->where('source_type', 'experiment')
                                ->where('project_id', $projectId);
                        });
                    }
                }),
                default => $builder->where('agent_id', $agentId)
                    ->when($projectId, fn ($q) => $q->where(function ($sub) use ($projectId) {
                        $sub->where('project_id', $projectId)
                            ->orWhereNull('project_id');
                    })),
            };

            $results = $builder->limit($topK)->get();

            // Batch update last_accessed_at for retrieved memories
            if ($results->isNotEmpty()) {
                Memory::withoutGlobalScopes()
                    ->whereIn('id', $results->pluck('id'))
                    ->update(['last_accessed_at' => now()]);
            }

            return $results;
        } catch (\Throwable $e) {
            Log::warning('RetrieveRelevantMemoriesAction: Failed to retrieve memories', [
                'agent_id' => $agentId,
                'scope' => $scope,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Generate embedding vector for the query string.
     *
     * @return string Vector string for pgvector
     */
    private function generateEmbedding(string $text): string
    {
        $model = config('memory.embedding_model', 'text-embedding-3-small');

        $response = Prism::embeddings()
            ->using(config('memory.embedding_provider', 'openai'), $model)
            ->fromInput($text)
            ->asEmbeddings();

        $vector = $response->embeddings[0]->embedding;

        return '['.implode(',', $vector).']';
    }
}
