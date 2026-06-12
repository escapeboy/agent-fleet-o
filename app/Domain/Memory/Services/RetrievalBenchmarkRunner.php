<?php

namespace App\Domain\Memory\Services;

use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Memory\Models\Memory;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs a labeled retrieval dataset against the live unified memory search
 * stack and reports Recall@k / MRR / NDCG@k, so RRF weight changes can be
 * measured instead of guessed.
 *
 * Fixture documents are inserted as Memory rows directly (NOT through
 * StoreMemoryAction): its write-gate dedup/merge would silently combine
 * similar fixture documents and corrupt the ground truth.
 */
class RetrievalBenchmarkRunner
{
    public function __construct(
        private readonly UnifiedMemorySearchAction $search,
    ) {}

    /**
     * @param  array{name?: string, documents: array<int, array{key: string, content: string, importance?: float, tags?: array<int, string>}>, cases: array<int, array{query: string, relevant: array<int, string>}>}  $dataset
     * @return array{name: string, k: int, vector_lane: bool, cases: array<int, array{query: string, recall: float|null, mrr: float|null, ndcg: float|null, retrieved: array<int, string>}>, means: array{recall: float|null, mrr: float|null, ndcg: float|null}, fixtures_kept: bool}
     */
    public function run(array $dataset, string $teamId, string $agentId, int $k = 10, bool $keep = false): array
    {
        $this->validate($dataset);

        [$keyByMemoryId, $createdIds, $vectorLane] = $this->ingest($dataset['documents'], $teamId, $agentId);

        try {
            $cases = [];

            foreach ($dataset['cases'] as $case) {
                $results = $this->search->execute(
                    teamId: $teamId,
                    query: $case['query'],
                    agentId: $agentId,
                    topK: $k,
                );

                // Preserve rank positions: non-fixture results (other memories,
                // KG facts) count as non-relevant instead of being dropped,
                // otherwise scores would be inflated.
                $retrieved = $results
                    ->map(function (array $item, int $index) use ($keyByMemoryId) {
                        $memoryId = $item['metadata']['id'] ?? null;

                        return $keyByMemoryId[$memoryId] ?? 'ext:'.$index;
                    })
                    ->values()
                    ->all();

                $cases[] = [
                    'query' => $case['query'],
                    'recall' => RetrievalMetrics::recallAtK($case['relevant'], $retrieved, $k),
                    'mrr' => RetrievalMetrics::mrr($case['relevant'], $retrieved),
                    'ndcg' => RetrievalMetrics::ndcgAtK($case['relevant'], $retrieved, $k),
                    'retrieved' => $retrieved,
                ];
            }

            return [
                'name' => $dataset['name'] ?? 'unnamed',
                'k' => $k,
                'vector_lane' => $vectorLane,
                'cases' => $cases,
                'means' => [
                    'recall' => $this->mean(array_column($cases, 'recall')),
                    'mrr' => $this->mean(array_column($cases, 'mrr')),
                    'ndcg' => $this->mean(array_column($cases, 'ndcg')),
                ],
                'fixtures_kept' => $keep,
            ];
        } finally {
            if (! $keep) {
                Memory::withoutGlobalScopes()->whereIn('id', $createdIds)->delete();
            }
        }
    }

    /**
     * @throws \InvalidArgumentException on malformed datasets
     */
    public function validate(array $dataset): void
    {
        if (empty($dataset['documents']) || ! is_array($dataset['documents'])) {
            throw new \InvalidArgumentException('Dataset must contain a non-empty "documents" array.');
        }

        if (empty($dataset['cases']) || ! is_array($dataset['cases'])) {
            throw new \InvalidArgumentException('Dataset must contain a non-empty "cases" array.');
        }

        $keys = [];
        foreach ($dataset['documents'] as $i => $doc) {
            if (empty($doc['key']) || ! isset($doc['content']) || trim((string) $doc['content']) === '') {
                throw new \InvalidArgumentException("Document #{$i} must have a non-empty \"key\" and \"content\".");
            }
            $keys[$doc['key']] = true;
        }

        foreach ($dataset['cases'] as $i => $case) {
            if (empty($case['query']) || ! isset($case['relevant']) || ! is_array($case['relevant'])) {
                throw new \InvalidArgumentException("Case #{$i} must have a \"query\" and a \"relevant\" array.");
            }
            foreach ($case['relevant'] as $key) {
                if (! isset($keys[$key])) {
                    throw new \InvalidArgumentException("Case #{$i} references unknown document key \"{$key}\".");
                }
            }
        }
    }

    /**
     * @return array{0: array<string, string>, 1: array<int, string>, 2: bool}
     */
    private function ingest(array $documents, string $teamId, string $agentId): array
    {
        $keyByMemoryId = [];
        $createdIds = [];

        // The embedding column is pgsql-only (vector(1536), see the
        // create_memories migration) — on other drivers the vector lane
        // cannot participate at all.
        $vectorLane = DB::getDriverName() === 'pgsql';

        $provider = app(EmbeddingProviderInterface::class);

        foreach ($documents as $doc) {
            $embedding = null;

            if ($vectorLane) {
                try {
                    $embedding = $provider->formatForPgvector($provider->embed($doc['content']));
                } catch (\Throwable $e) {
                    // Degrade exactly like the search path: keyword lane still works.
                    $vectorLane = false;
                    Log::warning('RetrievalBenchmark: embedding unavailable, vector lane disabled', ['error' => $e->getMessage()]);
                }
            }

            $attributes = [
                'team_id' => $teamId,
                'agent_id' => $agentId,
                'content' => $doc['content'],
                'metadata' => ['benchmark_key' => $doc['key']],
                'source_type' => 'benchmark',
                'confidence' => 1.0,
                'importance' => (float) ($doc['importance'] ?? 0.5),
                'tags' => $doc['tags'] ?? ['benchmark'],
                'visibility' => MemoryVisibility::Team,
                'content_hash' => hash('sha256', $doc['content']),
            ];

            if ($embedding !== null) {
                $attributes['embedding'] = $embedding;
            }

            $memory = Memory::withoutGlobalScopes()->create($attributes);

            $keyByMemoryId[$memory->id] = $doc['key'];
            $createdIds[] = $memory->id;
        }

        return [$keyByMemoryId, $createdIds, $vectorLane];
    }

    /**
     * @param  array<int, float|null>  $values
     */
    private function mean(array $values): ?float
    {
        $defined = array_values(array_filter($values, fn ($v) => $v !== null));

        return $defined === [] ? null : array_sum($defined) / count($defined);
    }
}
