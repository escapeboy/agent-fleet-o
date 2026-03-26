<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\Models\CodeElement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid code element search over code_elements using pgvector (semantic) and
 * tsvector (keyword) on PostgreSQL. Falls back to keyword-only on SQLite/test environments.
 *
 * Score formula: semanticWeight * cosine_similarity + keywordWeight * ts_rank
 */
class CodeRetriever
{
    /**
     * Search for code elements matching the given natural-language query.
     *
     * @return Collection<int, CodeElement>
     */
    public function search(
        string $teamId,
        string $repositoryId,
        string $query,
        int $limit = 5,
        float $semanticWeight = 0.6,
        float $keywordWeight = 0.3,
    ): Collection {
        if ($this->pgvectorAvailable()) {
            return $this->hybridSearch($teamId, $repositoryId, $query, $limit, $semanticWeight, $keywordWeight);
        }

        return $this->keywordSearch($teamId, $repositoryId, $query, $limit);
    }

    private function hybridSearch(
        string $teamId,
        string $repositoryId,
        string $query,
        int $limit,
        float $semanticWeight,
        float $keywordWeight,
    ): Collection {
        // Elements without embeddings fall back to keyword scoring only.
        // We use a CASE expression so both cases are handled in a single query.
        $tsQuery = $this->toTsQuery($query);

        $results = DB::select(
            <<<'SQL'
            SELECT
                id,
                CASE
                    WHEN embedding IS NOT NULL
                    THEN (1 - (embedding <=> embedding)) * :sw
                    ELSE 0
                END
                + CASE
                    WHEN search_vector IS NOT NULL
                    THEN ts_rank(search_vector, to_tsquery('english', :tsq2)) * :kw
                    ELSE 0
                END AS score
            FROM code_elements
            WHERE team_id = :team_id
              AND git_repository_id = :repo_id
              AND element_type != 'file'
              AND (
                  search_vector @@ to_tsquery('english', :tsq3)
                  OR embedding IS NOT NULL
              )
            ORDER BY score DESC
            LIMIT :lim
            SQL,
            [
                'sw' => $semanticWeight,
                'tsq2' => $tsQuery,
                'kw' => $keywordWeight,
                'team_id' => $teamId,
                'repo_id' => $repositoryId,
                'tsq3' => $tsQuery,
                'lim' => $limit,
            ],
        );

        if (empty($results)) {
            return collect();
        }

        $ids = array_column($results, 'id');

        // Validate that all IDs are syntactically valid UUIDs before interpolating
        // into the raw SQL expression (defence-in-depth against injection).
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $ids = array_values(array_filter($ids, fn ($id) => is_string($id) && preg_match($uuidPattern, $id)));

        if (empty($ids)) {
            return collect();
        }

        return CodeElement::whereIn('id', $ids)
            ->orderByRaw('array_position(ARRAY['.implode(',', array_map(fn ($id) => "'{$id}'", $ids)).']::uuid[], id)')
            ->get();
    }

    private function keywordSearch(
        string $teamId,
        string $repositoryId,
        string $query,
        int $limit,
    ): Collection {
        // Plain LIKE search for SQLite/test environments where tsvector is unavailable.
        $words = array_filter(explode(' ', $query));
        $queryBuilder = CodeElement::where('team_id', $teamId)
            ->where('git_repository_id', $repositoryId)
            ->where('element_type', '!=', 'file');

        foreach ($words as $word) {
            $queryBuilder->where(function ($q) use ($word): void {
                $q->where('name', 'like', "%{$word}%")
                    ->orWhere('signature', 'like', "%{$word}%")
                    ->orWhere('docstring', 'like', "%{$word}%");
            });
        }

        return $queryBuilder->limit($limit)->get();
    }

    private function pgvectorAvailable(): bool
    {
        try {
            $count = DB::selectOne(
                "SELECT COUNT(*) AS cnt FROM pg_extension WHERE extname = 'vector'",
            );

            return ($count->cnt ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Convert a free-text query into a tsquery-safe string.
     * Strips non-word characters and joins tokens with &.
     */
    private function toTsQuery(string $query): string
    {
        $words = preg_split('/\s+/', trim($query));
        $words = array_filter($words, fn ($w) => preg_match('/^\w+$/', (string) $w));
        $words = array_values($words);

        if (empty($words)) {
            return 'code';
        }

        return implode(' & ', $words);
    }
}
