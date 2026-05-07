<?php

namespace App\Domain\Memory\Actions;

use App\Domain\KnowledgeGraph\Services\TemporalKnowledgeGraphService;
use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Unified search across vector memory, knowledge graph, and keyword search
 * using Reciprocal Rank Fusion (RRF) to produce a single ranked list.
 *
 * RRF_score(item) = Σ [ weight_i / (rank_in_system_i + k) ]
 */
class UnifiedMemorySearchAction
{
    public function __construct(
        private readonly RetrieveRelevantMemoriesAction $vectorSearch,
        private readonly TemporalKnowledgeGraphService $kgService,
    ) {}

    /**
     * @return Collection<int, array{type: string, content: string, score: float, metadata: array}>
     */
    /**
     * @param  string[]|null  $tags  When provided, only return memories containing ANY of these tags.
     */
    public function execute(
        string $teamId,
        string $query,
        ?string $agentId = null,
        ?string $projectId = null,
        int $topK = 10,
        ?array $tags = null,
        ?string $topic = null,
    ): Collection {
        if (! config('memory.unified_search.enabled', true)) {
            // Fall back to vector-only search
            return $this->vectorOnlyFallback($agentId, $query, $projectId, $topK, $teamId, $tags, $topic);
        }

        $vectorWeight = config('memory.unified_search.vector_weight', 1.0);
        $kgWeight = config('memory.unified_search.kg_weight', 2.0);
        $keywordWeight = config('memory.unified_search.keyword_weight', 0.5);
        $rrfK = config('memory.unified_search.rrf_k', 60);

        $queryEmbedding = $this->generateEmbedding($query);

        // System 1: Vector search — run original + keyword-expanded query variants in parallel,
        // then merge with weighted RRF (Onyx-inspired multi-query wRRF).
        $vectorResults = $this->getVectorResultsMultiQuery($agentId, $query, $projectId, $teamId, $tags, $topic, $rrfK);

        // System 2: Knowledge Graph search
        $kgResults = $this->getKgResults($teamId, $queryEmbedding);

        // System 3: Keyword search
        $keywordResults = $this->getKeywordResults($teamId, $agentId, $query);

        // RRF fusion
        $fused = collect();

        // Assign ranks and scores for vector results
        $vectorResults->values()->each(function ($item, $rank) use ($fused, $vectorWeight, $rrfK) {
            $key = 'memory:'.$item->id;
            $rrfScore = $vectorWeight / ($rank + 1 + $rrfK);
            $existing = $fused->get($key);

            $fused->put($key, [
                'type' => 'memory',
                'content' => $item->content,
                'score' => ($existing['score'] ?? 0) + $rrfScore,
                'metadata' => [
                    'id' => $item->id,
                    'source_type' => $item->source_type,
                    'agent_id' => $item->agent_id,
                    'importance' => $item->effective_importance,
                    'retrieval_count' => $item->retrieval_count ?? 0,
                    'created_at' => $item->created_at?->toIso8601String(),
                    'confidence' => $item->confidence,
                    'metadata' => $item->metadata,
                ],
            ]);
        });

        // Assign ranks and scores for KG results
        $kgResults->values()->each(function ($edge, $rank) use ($fused, $kgWeight, $rrfK) {
            $key = 'kg:'.$edge->id;
            $rrfScore = $kgWeight / ($rank + 1 + $rrfK);

            $source = $edge->sourceEntity->name ?? 'Unknown';
            $target = $edge->targetEntity->name ?? 'Unknown';

            $fused->put($key, [
                'type' => 'kg_fact',
                'content' => $edge->fact,
                'score' => $rrfScore,
                'metadata' => [
                    'id' => $edge->id,
                    'source_entity' => $source,
                    'target_entity' => $target,
                    'relation_type' => $edge->relation_type,
                    'valid_at' => $edge->valid_at?->toIso8601String(),
                ],
            ]);
        });

        // Assign ranks and scores for keyword results
        $keywordResults->values()->each(function ($item, $rank) use ($fused, $keywordWeight, $rrfK) {
            $key = 'memory:'.$item->id;
            $rrfScore = $keywordWeight / ($rank + 1 + $rrfK);
            $existing = $fused->get($key);

            if ($existing) {
                // Memory already found via vector — boost its score
                $existing['score'] += $rrfScore;
                $fused->put($key, $existing);
            } else {
                $fused->put($key, [
                    'type' => 'memory',
                    'content' => $item->content,
                    'score' => $rrfScore,
                    'metadata' => [
                        'id' => $item->id,
                        'source_type' => $item->source_type,
                        'agent_id' => $item->agent_id,
                        'importance' => $item->effective_importance,
                        'retrieval_count' => $item->retrieval_count ?? 0,
                        'created_at' => $item->created_at?->toIso8601String(),
                        'confidence' => $item->confidence,
                        'metadata' => $item->metadata,
                    ],
                ]);
            }
        });

        return $fused->values()
            ->sortByDesc('score')
            ->take($topK)
            ->values();
    }

    /**
     * Run the original query + a keyword-expanded variant in parallel and merge
     * with weighted Reciprocal Rank Fusion.
     *
     * Formula: score(item) = Σ weight_i / (k + rank_i)
     * Weights: original=0.7, keyword_expanded=0.3 (BM25-like bias for expansion).
     */
    private function getVectorResultsMultiQuery(
        ?string $agentId,
        string $query,
        ?string $projectId,
        ?string $teamId,
        ?array $tags,
        ?string $topic,
        int $k,
    ): Collection {
        if (! $agentId) {
            return collect();
        }

        try {
            $originalResults = $this->vectorSearch->execute(
                agentId: $agentId,
                query: $query,
                projectId: $projectId,
                topK: 25,
                scope: $projectId ? 'project' : 'agent',
                teamId: $teamId,
                tags: $tags,
                topic: $topic,
            );

            $expandedQuery = $this->expandKeywords($query);
            $expandedResults = ($expandedQuery !== $query)
                ? $this->vectorSearch->execute(
                    agentId: $agentId,
                    query: $expandedQuery,
                    projectId: $projectId,
                    topK: 25,
                    scope: $projectId ? 'project' : 'agent',
                    teamId: $teamId,
                    tags: $tags,
                    topic: $topic,
                )
                : collect();

            return $this->weightedRrf([
                ['results' => $originalResults, 'weight' => 0.7],
                ['results' => $expandedResults, 'weight' => 0.3],
            ], $k);
        } catch (\Throwable $e) {
            Log::debug('UnifiedSearch: multi-query vector search failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Weighted Reciprocal Rank Fusion across multiple result sets.
     *
     * @param  array<int, array{results: Collection, weight: float}>  $rankedLists
     */
    private function weightedRrf(array $rankedLists, int $k): Collection
    {
        $scores = [];
        $items = [];

        foreach ($rankedLists as $list) {
            $list['results']->values()->each(function ($item, int $rank) use (&$scores, &$items, $list, $k) {
                $id = $item->id;
                $scores[$id] = ($scores[$id] ?? 0.0) + ($list['weight'] / ($k + $rank + 1));
                $items[$id] = $item;
            });
        }

        arsort($scores);

        return collect(array_keys($scores))->map(fn ($id) => $items[$id])->values();
    }

    /**
     * Keyword expansion: remove common English stopwords and de-duplicate terms.
     * Returns a cleaned, space-separated query string for BM25-style recall.
     */
    private function expandKeywords(string $query): string
    {
        static $stopwords = [
            'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'may', 'might', 'shall', 'can', 'need', 'dare', 'ought',
            'in', 'on', 'at', 'to', 'for', 'of', 'by', 'with', 'from', 'about',
            'into', 'through', 'during', 'before', 'after', 'above', 'below',
            'and', 'or', 'but', 'not', 'nor', 'so', 'yet', 'both', 'either',
            'what', 'which', 'who', 'whom', 'how', 'where', 'when', 'why',
            'this', 'that', 'these', 'those', 'it', 'its', 'i', 'me', 'my',
            'we', 'our', 'you', 'your', 'he', 'him', 'his', 'she', 'her',
            'they', 'their', 'them', 'all', 'any', 'some', 'no', 'more',
            'most', 'other', 'also', 'just', 'than', 'then', 'there', 'here',
        ];

        $words = preg_split('/\W+/', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);

        $keywords = array_values(array_unique(
            array_filter($words, fn ($w) => strlen($w) >= 3 && ! in_array($w, $stopwords, true)),
        ));

        return $keywords !== [] ? implode(' ', $keywords) : $query;
    }

    private function getVectorResults(?string $agentId, string $query, ?string $projectId, ?string $teamId, ?array $tags = null, ?string $topic = null): Collection
    {
        if (! $agentId) {
            return collect();
        }

        try {
            return $this->vectorSearch->execute(
                agentId: $agentId,
                query: $query,
                projectId: $projectId,
                topK: 20,
                scope: $projectId ? 'project' : 'agent',
                teamId: $teamId,
                tags: $tags,
                topic: $topic,
            );
        } catch (\Throwable $e) {
            Log::debug('UnifiedSearch: vector search failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    private function getKgResults(string $teamId, string $queryEmbedding): Collection
    {
        try {
            return $this->kgService->search(
                teamId: $teamId,
                queryEmbedding: $queryEmbedding,
                limit: 20,
            );
        } catch (\Throwable $e) {
            Log::debug('UnifiedSearch: KG search failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    private function getKeywordResults(string $teamId, ?string $agentId, string $query): Collection
    {
        try {
            // PostgreSQL: use full-text search with BM25 ranking (ts_rank_cd).
            // Falls back to ILIKE for SQLite (tests) or if content_tsv column is absent.
            if (\DB::getDriverName() === 'pgsql') {
                return $this->getFtsResults($teamId, $agentId, $query);
            }

            return $this->getIlikeResults($teamId, $agentId, $query);
        } catch (\Throwable $e) {
            Log::debug('UnifiedSearch: keyword search failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    private function getFtsResults(string $teamId, ?string $agentId, string $query): Collection
    {
        $safeQuery = str_replace(['\'', '\\'], ['\'\'', '\\\\'], $query);

        $builder = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereRaw("content_tsv @@ plainto_tsquery('english', ?)", [$safeQuery])
            ->orderByRaw("ts_rank_cd(content_tsv, plainto_tsquery('english', ?)) DESC", [$safeQuery])
            ->limit(20);

        if ($agentId) {
            $builder->where('agent_id', $agentId);
        }

        $results = $builder->get();

        // Trigram fallback for short queries and proper nouns that tokenise poorly.
        if ($results->isEmpty() && mb_strlen($query) >= 3) {
            $trigramBuilder = Memory::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereRaw('content % ?', [$query])
                ->orderByRaw('similarity(content, ?) DESC', [$query])
                ->limit(10);

            if ($agentId) {
                $trigramBuilder->where('agent_id', $agentId);
            }

            $results = $trigramBuilder->get();
        }

        return $results;
    }

    private function getIlikeResults(string $teamId, ?string $agentId, string $query): Collection
    {
        $builder = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('content', 'ILIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%')
            ->orderByDesc('importance')
            ->limit(20);

        if ($agentId) {
            $builder->where('agent_id', $agentId);
        }

        return $builder->get();
    }

    private function vectorOnlyFallback(?string $agentId, string $query, ?string $projectId, int $topK, ?string $teamId, ?array $tags = null, ?string $topic = null): Collection
    {
        if (! $agentId) {
            return collect();
        }

        $memories = $this->vectorSearch->execute(
            agentId: $agentId,
            query: $query,
            projectId: $projectId,
            topK: $topK,
            scope: $projectId ? 'project' : 'agent',
            teamId: $teamId,
            tags: $tags,
            topic: $topic,
        );

        return $memories->map(fn ($m) => [
            'type' => 'memory',
            'content' => $m->content,
            'score' => $m->composite_score ?? 0,
            'metadata' => [
                'id' => $m->id,
                'source_type' => $m->source_type,
                'agent_id' => $m->agent_id,
                'importance' => $m->effective_importance,
                'retrieval_count' => $m->retrieval_count ?? 0,
                'created_at' => $m->created_at?->toIso8601String(),
                'confidence' => $m->confidence,
            ],
        ]);
    }

    private function generateEmbedding(string $text): string
    {
        $response = Prism::embeddings()
            ->using(config('memory.embedding_provider', 'openai'), config('memory.embedding_model', 'text-embedding-3-small'))
            ->fromInput($text)
            ->asEmbeddings();

        return '['.implode(',', $response->embeddings[0]->embedding).']';
    }
}
