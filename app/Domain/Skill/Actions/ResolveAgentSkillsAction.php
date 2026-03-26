<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResolveAgentSkillsAction
{
    /**
     * Resolve the most relevant skills for a given task using hybrid BM25 + pgvector retrieval.
     * Returns at most config('skills.hybrid_retrieval.max_injected') skills.
     *
     * @return Collection<int, Skill>
     */
    public function execute(string $teamId, string $taskDescription): Collection
    {
        if (! config('skills.hybrid_retrieval.enabled', true)) {
            return collect();
        }

        $maxInjected = config('skills.hybrid_retrieval.max_injected', 2);

        // Step 1: BM25 full-text search
        $bm25Candidates = $this->fullTextSearch($teamId, $taskDescription, 20);

        // Step 2: Semantic search (only on PostgreSQL)
        $semanticCandidates = collect();
        if (DB::getDriverName() === 'pgsql') {
            $semanticCandidates = $this->semanticSearch($teamId, $taskDescription, 20);
        }

        // Step 3: Merge and re-rank
        $merged = $this->mergeAndRank($bm25Candidates, $semanticCandidates);

        // Step 4: Return top-K
        $topK = $merged->take($maxInjected);

        if ($topK->isNotEmpty()) {
            // Increment applied_count for each injected skill (atomic)
            $skillIds = $topK->pluck('id')->toArray();
            DB::table('skills')->whereIn('id', $skillIds)->increment('applied_count');
        }

        return $topK;
    }

    /**
     * Full-text BM25 search using PostgreSQL ts_rank or SQLite LIKE fallback.
     *
     * @return Collection<int, array{skill: Skill, bm25_score: float}>
     */
    private function fullTextSearch(string $teamId, string $taskDescription, int $limit): Collection
    {
        // Sanitize query for to_tsquery
        $query = trim(preg_replace('/[^\w\s]/u', ' ', $taskDescription));
        if (empty($query)) {
            return collect();
        }

        try {
            if (DB::getDriverName() === 'pgsql') {
                $skills = DB::select("
                    SELECT id, ts_rank(
                        to_tsvector('english', coalesce(name,'') || ' ' || coalesce(description,'')),
                        plainto_tsquery('english', ?)
                    ) AS bm25_score
                    FROM skills
                    WHERE team_id = ?
                      AND status = 'active'
                      AND deleted_at IS NULL
                      AND to_tsvector('english', coalesce(name,'') || ' ' || coalesce(description,''))
                          @@ plainto_tsquery('english', ?)
                    ORDER BY bm25_score DESC
                    LIMIT ?
                ", [$query, $teamId, $query, $limit]);

                $skillIds = collect($skills)->pluck('id');
                $scoreMap = collect($skills)->keyBy('id')->map(fn ($r) => (float) $r->bm25_score);

                return Skill::query()
                    ->whereIn('id', $skillIds)
                    ->where('status', 'active')
                    ->get()
                    ->map(fn ($skill) => [
                        'skill' => $skill,
                        'bm25_score' => $scoreMap[$skill->id] ?? 0.0,
                    ]);
            }

            // SQLite fallback: simple LIKE search
            return Skill::query()
                ->where('team_id', $teamId)
                ->where('status', 'active')
                ->where(function ($q) use ($query) {
                    foreach (explode(' ', $query) as $word) {
                        if (strlen($word) > 2) {
                            $q->orWhere('name', 'like', "%{$word}%")
                                ->orWhere('description', 'like', "%{$word}%");
                        }
                    }
                })
                ->limit($limit)
                ->get()
                ->map(fn ($skill) => ['skill' => $skill, 'bm25_score' => 0.5]);
        } catch (\Throwable $e) {
            Log::warning('ResolveAgentSkillsAction: BM25 search failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Semantic search using pgvector cosine similarity on skill_embeddings.
     *
     * @return Collection<int, array{skill: Skill, semantic_score: float}>
     */
    private function semanticSearch(string $teamId, string $taskDescription, int $limit): Collection
    {
        $apiKey = config('prism.providers.openai.api_key') ?? env('OPENAI_API_KEY');
        if (empty($apiKey)) {
            return collect();
        }

        try {
            $embeddingResponse = Http::withToken($apiKey)
                ->post('https://api.openai.com/v1/embeddings', [
                    'input' => $taskDescription,
                    'model' => config('skills.hybrid_retrieval.embedding_model', 'text-embedding-3-small'),
                ])
                ->throw()
                ->json();

            $embedding = $embeddingResponse['data'][0]['embedding'] ?? null;
            if (! $embedding) {
                return collect();
            }

            $threshold = config('skills.hybrid_retrieval.semantic_threshold', 0.65);
            $vectorStr = '['.implode(',', $embedding).']';

            $results = DB::select("
                SELECT se.skill_id, (1 - (se.embedding <=> ?::vector)) AS similarity
                FROM skill_embeddings se
                JOIN skills s ON s.id = se.skill_id
                WHERE s.team_id = ?
                  AND s.status = 'active'
                  AND s.deleted_at IS NULL
                  AND se.embedding IS NOT NULL
                  AND (1 - (se.embedding <=> ?::vector)) >= ?
                ORDER BY similarity DESC
                LIMIT ?
            ", [$vectorStr, $teamId, $vectorStr, $threshold, $limit]);

            $skillIds = collect($results)->pluck('skill_id');
            $scoreMap = collect($results)->keyBy('skill_id')->map(fn ($r) => (float) $r->similarity);

            return Skill::query()
                ->whereIn('id', $skillIds)
                ->where('status', 'active')
                ->get()
                ->map(fn ($skill) => [
                    'skill' => $skill,
                    'semantic_score' => $scoreMap[$skill->id] ?? 0.0,
                ]);
        } catch (\Throwable $e) {
            Log::warning('ResolveAgentSkillsAction: semantic search failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Merge BM25 and semantic candidates, compute hybrid score, deduplicate.
     *
     * @param  Collection<int, array{skill: Skill, bm25_score: float}>  $bm25
     * @param  Collection<int, array{skill: Skill, semantic_score: float}>  $semantic
     * @return Collection<int, Skill>
     */
    private function mergeAndRank(Collection $bm25, Collection $semantic): Collection
    {
        $bm25Weight = config('skills.hybrid_retrieval.bm25_weight', 0.4);
        $semanticWeight = config('skills.hybrid_retrieval.semantic_weight', 0.6);

        $scores = [];

        foreach ($bm25 as $entry) {
            $id = $entry['skill']->id;
            $scores[$id] = ['skill' => $entry['skill'], 'score' => $entry['bm25_score'] * $bm25Weight];
        }

        foreach ($semantic as $entry) {
            $id = $entry['skill']->id;
            if (isset($scores[$id])) {
                $scores[$id]['score'] += $entry['semantic_score'] * $semanticWeight;
            } else {
                $scores[$id] = ['skill' => $entry['skill'], 'score' => $entry['semantic_score'] * $semanticWeight];
            }
        }

        return collect($scores)
            ->sortByDesc('score')
            ->pluck('skill');
    }
}
