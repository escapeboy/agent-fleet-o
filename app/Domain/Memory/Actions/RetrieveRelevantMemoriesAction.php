<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Memory\Models\Memory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

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
    ): Collection {
        if (! config('memory.enabled', true)) {
            return collect();
        }

        $topK = $topK ?? config('memory.top_k', 5);
        $threshold = $threshold ?? config('memory.similarity_threshold', 0.7);

        try {
            $queryEmbedding = $this->generateEmbedding($query);

            $semanticWeight = config('memory.scoring.semantic_weight', 0.5);
            $recencyWeight = config('memory.scoring.recency_weight', 0.3);
            $importanceWeight = config('memory.scoring.importance_weight', 0.2);
            $halfLifeDays = config('memory.scoring.half_life_days', 7);

            // Composite score using effective_importance (base + retrieval reinforcement).
            // Curated tiers (canonical, facts, decisions, failures, successes) receive a +0.10 boost
            // so human-approved knowledge surfaces preferentially over agent-proposed memories.
            // User feedback boost: COALESCE(boost, 0) * 0.05 — max +0.5 for boost=10.
            $compositeScoreSql = <<<'SQL'
                (? * (1 - (embedding <=> ?))) +
                (? * POWER(0.5, EXTRACT(EPOCH FROM (NOW() - COALESCE(last_accessed_at, created_at))) / 86400.0 / ?)) +
                (? * LEAST(COALESCE(importance, 0.5) + LN(1 + COALESCE(retrieval_count, 0)) * 0.15, 1.0)) +
                CASE WHEN COALESCE(tier, 'working') IN ('canonical','facts','decisions','failures','successes') THEN 0.10 ELSE 0.0 END +
                (COALESCE(boost, 0) * 0.05)
                AS composite_score
            SQL;

            $builder = Memory::withoutGlobalScopes()
                ->select('memories.*')
                ->selectRaw($compositeScoreSql, [
                    $semanticWeight, $queryEmbedding,
                    $recencyWeight, $halfLifeDays,
                    $importanceWeight,
                ])
                ->whereRaw('1 - (embedding <=> ?) >= ?', [$queryEmbedding, $threshold])
                ->where('confidence', '>=', $minConfidence)
                ->orderByDesc('composite_score');

            // Topic namespace pre-filter: narrows the candidate set before the pgvector scan.
            // Skipped when topic is null to preserve backwards-compatible behaviour.
            if ($topic !== null) {
                $builder->where('topic', $topic);
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
            // ?| checks if the JSONB array contains ANY of the given text values
            $builder->whereRaw('tags ?| ?', ['{'.implode(',', $tags).'}']);
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
