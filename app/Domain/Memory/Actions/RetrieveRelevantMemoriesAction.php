<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Memory\Models\Memory;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use App\Infrastructure\AI\Services\TokenEstimator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RetrieveRelevantMemoriesAction
{
    /**
     * Retrieve top-K relevant memories using composite scoring with effective importance.
     *
     * Effective importance = LEAST(importance + LN(1 + retrieval_count) * 0.15, 1.0)
     *
     * @param  string  $scope  'agent' (default), 'team', or 'project'
     * @return Collection<int, Memory>
     */
    /**
     * @param  string[]|null  $tags  When provided, only return memories containing ANY of these tags (JSONB ?| operator on PostgreSQL).
     */
    public function execute(
        ?string $agentId,
        string $query,
        ?string $projectId = null,
        ?int $topK = null,
        ?float $threshold = null,
        string $scope = 'agent',
        ?string $teamId = null,
        float $minConfidence = 0.3,
        ?array $tags = null,
        ?string $topic = null,
        ?string $domain = null,
        bool $excludePreferences = false,
    ): Collection {
        if (! config('memory.enabled', true)) {
            return collect();
        }

        $topK = $topK ?? config('memory.top_k', 5);
        $threshold = $threshold ?? config('memory.similarity_threshold', 0.7);

        try {
            // Skip the embedding call (and the semantic clauses that depend on it)
            // when the query text is blank. OpenAI's embeddings endpoint rejects an
            // empty input with a 400 "Invalid 'input[0]'"; without a query we simply
            // score by recency + importance instead of semantic similarity.
            $hasQuery = trim($query) !== '';
            $queryEmbedding = $hasQuery ? $this->generateEmbedding($query, $teamId) : null;
            // When no embedding is available (e.g. a BYOK team whose provider
            // key isn't set yet), fall back to recency + importance scoring
            // instead of returning nothing — the semantic clauses are skipped.
            $hasQuery = $hasQuery && $queryEmbedding !== null;

            $semanticWeight = config('memory.scoring.semantic_weight', 0.5);
            $recencyWeight = config('memory.scoring.recency_weight', 0.3);
            $importanceWeight = config('memory.scoring.importance_weight', 0.2);
            $halfLifeDays = config('memory.scoring.half_life_days', 7);

            // Composite score using effective_importance (base + retrieval reinforcement).
            // Curated tiers (canonical, facts, decisions, failures, successes) receive a +0.10 boost
            // so human-approved knowledge surfaces preferentially over agent-proposed memories.
            // User feedback boost: COALESCE(boost, 0) * 0.05 — max +0.5 for boost=10.
            // The semantic term is only included when a query embedding exists.
            $semanticTerm = $hasQuery ? '(? * (1 - (embedding <=> ?))) +' : '';
            $compositeScoreSql = <<<SQL
                {$semanticTerm}
                (? * POWER(0.5, EXTRACT(EPOCH FROM (NOW() - COALESCE(last_accessed_at, created_at))) / 86400.0 / ?)) +
                (? * LEAST(COALESCE(importance, 0.5) + LN(1 + COALESCE(retrieval_count, 0)) * 0.15, 1.0)) +
                CASE WHEN COALESCE(tier, 'working') IN ('canonical','facts','decisions','failures','successes') THEN 0.10 ELSE 0.0 END +
                (COALESCE(boost, 0) * 0.05)
                AS composite_score
            SQL;

            $compositeBindings = $hasQuery
                ? [$semanticWeight, $queryEmbedding, $recencyWeight, $halfLifeDays, $importanceWeight]
                : [$recencyWeight, $halfLifeDays, $importanceWeight];

            $builder = Memory::withoutGlobalScopes()
                ->select('memories.*')
                ->selectRaw($compositeScoreSql, $compositeBindings)
                // Raw cosine similarity, surfaced so callers can label results
                // high/standard/low (MemoryRelevance) instead of showing a bare
                // score. Only available when a query embedding exists.
                ->when($hasQuery, fn ($q) => $q->selectRaw('1 - (embedding <=> ?) AS similarity', [$queryEmbedding]))
                ->when($hasQuery, fn ($q) => $q->whereRaw('1 - (embedding <=> ?) >= ?', [$queryEmbedding, $threshold]))
                ->where('confidence', '>=', $minConfidence)
                // Exclude rejected proposals — keep NULL (legacy) and approved.
                ->where(fn ($q) => $q->whereNull('proposal_status')->orWhere('proposal_status', '!=', 'rejected'))
                // Superseded beliefs are retained for audit but never injected.
                ->where(fn ($q) => $q->whereNull('belief_status')->orWhere('belief_status', '!=', 'superseded'))
                ->orderByDesc('composite_score');

            // Path B (semantic discovery) excludes preference-category memories:
            // preferences are loaded in full by Path A (known-scope enumeration)
            // so they are never subject to the top-k cutoff. Excluding them here
            // avoids double-injection. NULL-category rows are kept.
            if ($excludePreferences) {
                $builder->where(fn ($q) => $q->where('category', '!=', 'preference')->orWhereNull('category'));
            }

            // Provisional (Proposed-tier) memories are durable but kept out of
            // semantic discovery until audited/promoted, when the flag is on.
            if (config('memory.exclude_provisional_from_discovery', false)) {
                $builder->where(fn ($q) => $q->where('tier', '!=', 'proposed')->orWhereNull('tier'));
            }

            // Topic namespace pre-filter: narrows the candidate set before the pgvector scan.
            // Skipped when topic is null to preserve backwards-compatible behaviour.
            if ($topic !== null) {
                $builder->where('topic', $topic);
            }

            // Domain scope: a hard filter applied after scoring. A belief scoped
            // to one domain (e.g. domain:code) never surfaces in another domain's
            // session. NULL-domain beliefs are universal and always eligible.
            if ($domain !== null) {
                $builder->where(fn ($q) => $q->where('domain', $domain)->orWhereNull('domain'));
            }

            // Tag-based filtering (opt-in: only applied when tags are passed)
            if (! empty($tags)) {
                $this->applyTagFilter($builder, $tags);
            }

            // Apply scope filtering with visibility awareness
            $this->applyScope($builder, $scope, $agentId, $projectId, $teamId);

            $results = $builder->limit($topK)->get();

            // Batch update last_accessed_at and increment retrieval_count
            if ($results->isNotEmpty()) {
                $updateQuery = Memory::withoutGlobalScopes()
                    ->whereIn('id', $results->pluck('id'));

                // Apply tenant guard
                match ($scope) {
                    'team' => $updateQuery->when($teamId, fn ($q) => $q->where('team_id', $teamId)),
                    default => $updateQuery->where('agent_id', $agentId),
                };

                $updateQuery->update(['last_accessed_at' => now()]);

                // Increment retrieval_count atomically (with tenant guard)
                $incrementQuery = Memory::withoutGlobalScopes()
                    ->whereIn('id', $results->pluck('id'));
                match ($scope) {
                    'team' => $incrementQuery->when($teamId, fn ($q) => $q->where('team_id', $teamId)),
                    default => $incrementQuery->where('agent_id', $agentId),
                };
                $incrementQuery->increment('retrieval_count');

                // Auto-promote private memories that cross the sharing threshold
                $this->checkAutoPromotion($results, $agentId);
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
     * Apply scope and visibility filters to the query builder.
     */
    private function applyScope(
        Builder $builder,
        string $scope,
        ?string $agentId,
        ?string $projectId,
        ?string $teamId,
    ): void {
        match ($scope) {
            'team' => $builder->when($teamId, fn ($q) => $q->where('team_id', $teamId)),
            'project' => $builder->where(function ($q) use ($agentId, $projectId, $teamId) {
                // Own private memories
                $q->where(fn ($sub) => $sub->where('agent_id', $agentId)->where('visibility', MemoryVisibility::Private));

                // Project-scoped memories from any agent in the project (team-guarded)
                if ($projectId) {
                    $q->orWhere(fn ($sub) => $sub->where('project_id', $projectId)
                        ->where('visibility', MemoryVisibility::Project)
                        ->when($teamId, fn ($s) => $s->where('team_id', $teamId)));
                }

                // Team-scoped memories
                if ($teamId) {
                    $q->orWhere(fn ($sub) => $sub->where('team_id', $teamId)->where('visibility', MemoryVisibility::Team));
                }
            }),
            default => $builder->where(function ($q) use ($agentId, $teamId) {
                // Own memories (any visibility)
                $q->where('agent_id', $agentId);

                // Also include team-scoped memories from other agents
                if ($teamId) {
                    $q->orWhere(fn ($sub) => $sub->where('team_id', $teamId)->where('visibility', MemoryVisibility::Team));
                }
            })->when($projectId, fn ($q) => $q->where(function ($sub) use ($projectId) {
                $sub->where('project_id', $projectId)
                    ->orWhereNull('project_id');
            })),
        };
    }

    /**
     * Auto-promote private memories that cross the sharing threshold.
     */
    private function checkAutoPromotion(Collection $memories, string $agentId): void
    {
        $minRetrievals = config('memory.visibility.auto_promote_retrievals', 3);
        $minImportance = config('memory.visibility.auto_promote_min_importance', 0.7);

        $candidates = $memories->filter(fn ($m) => $m->visibility === MemoryVisibility::Private
            && ($m->retrieval_count ?? 0) + 1 >= $minRetrievals // +1 because we just incremented
            && ($m->importance ?? 0.5) >= $minImportance,
        );

        if ($candidates->isNotEmpty()) {
            Memory::withoutGlobalScopes()
                ->whereIn('id', $candidates->pluck('id'))
                ->where('agent_id', $agentId)
                ->where('visibility', MemoryVisibility::Private)
                ->update(['visibility' => MemoryVisibility::Project]);
        }
    }

    /**
     * Filter memories that contain ANY of the given tags.
     * PostgreSQL: uses JSONB ?| (overlap) operator for GIN-indexed performance.
     * SQLite (tests): falls back to JSON_EACH + IN subquery.
     *
     * @param  string[]  $tags
     */
    private function applyTagFilter(Builder $builder, array $tags): void
    {
        if (config('database.default') === 'pgsql') {
            // jsonb_exists_any() is the function form of the JSONB ?| operator. The
            // operator form collides with PDO's ? placeholders ("syntax error at or
            // near ... tags $11| $12" → SQLSTATE[42601]); the function form takes a
            // bound text[] and is placeholder-safe.
            $placeholders = implode(',', array_fill(0, count($tags), '?'));
            $builder->whereRaw("jsonb_exists_any(tags, ARRAY[{$placeholders}]::text[])", array_values($tags));
        } else {
            // SQLite fallback: unnest the JSON array and check membership
            $placeholders = implode(',', array_fill(0, count($tags), '?'));
            $builder->whereRaw(
                "EXISTS (SELECT 1 FROM json_each(tags) WHERE json_each.value IN ({$placeholders}))",
                $tags,
            );
        }
    }

    /**
     * Generate a pgvector embedding string for the query, using the team's
     * BYOK credential. Returns null when no embedding is available (no key /
     * provider error) so the caller can fall back to non-semantic scoring
     * rather than failing the whole retrieval — the platform key is empty on
     * BYOK installs, so embed() would 401 here.
     *
     * @return string|null Vector string for pgvector, or null when unavailable
     */
    private function generateEmbedding(string $text, ?string $teamId): ?string
    {
        $provider = app(EmbeddingProviderInterface::class);

        $vector = $provider->embedForTeam($this->truncateToEmbeddingLimit($text), $teamId);

        return $vector === null ? null : $provider->formatForPgvector($vector);
    }

    /**
     * Truncate embedding input to the model's token limit. OpenAI's
     * text-embedding-3-* models reject inputs over 8192 tokens with a 400; a long
     * query (e.g. a whole pasted document) would otherwise fail the entire
     * retrieval. We estimate tokens via TokenEstimator's chars-per-token ratio and
     * cut on a character budget, leaving a small safety margin so the estimate
     * never undershoots the real tokenizer.
     */
    private function truncateToEmbeddingLimit(string $text): string
    {
        $maxTokens = (int) config('memory.embedding_max_input_tokens', 8192);

        $estimator = app(TokenEstimator::class);
        if ($estimator->estimate($text) <= $maxTokens) {
            return $text;
        }

        // 4 chars/token matches TokenEstimator::CHARS_PER_TOKEN; 0.95 margin keeps
        // the truncated string safely under the hard token cap.
        $charBudget = (int) floor($maxTokens * 4 * 0.95);

        return mb_substr($text, 0, $charBudget);
    }
}
