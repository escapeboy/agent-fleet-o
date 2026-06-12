<?php

namespace App\Domain\Memory\Services;

/**
 * Pure ranking-quality metrics (binary relevance) for retrieval benchmarks.
 * Keys are opaque strings; "retrieved" lists are rank-ordered, best first.
 */
class RetrievalMetrics
{
    /**
     * Fraction of relevant keys found within the top-k retrieved.
     * Returns null when there is nothing relevant to find (undefined metric).
     *
     * @param  array<int, string>  $relevant
     * @param  array<int, string>  $retrieved
     */
    public static function recallAtK(array $relevant, array $retrieved, int $k): ?float
    {
        if ($relevant === []) {
            return null;
        }

        $topK = array_slice($retrieved, 0, $k);
        $found = count(array_intersect(array_unique($relevant), array_unique($topK)));

        return $found / count(array_unique($relevant));
    }

    /**
     * Reciprocal rank of the first relevant result (1-based), 0.0 when none.
     *
     * @param  array<int, string>  $relevant
     * @param  array<int, string>  $retrieved
     */
    public static function mrr(array $relevant, array $retrieved): ?float
    {
        if ($relevant === []) {
            return null;
        }

        foreach (array_values($retrieved) as $index => $key) {
            if (in_array($key, $relevant, true)) {
                return 1.0 / ($index + 1);
            }
        }

        return 0.0;
    }

    /**
     * Normalized Discounted Cumulative Gain at k with binary relevance.
     * Returns null when there is nothing relevant (undefined metric).
     *
     * @param  array<int, string>  $relevant
     * @param  array<int, string>  $retrieved
     */
    public static function ndcgAtK(array $relevant, array $retrieved, int $k): ?float
    {
        if ($relevant === []) {
            return null;
        }

        $dcg = 0.0;
        foreach (array_slice(array_values($retrieved), 0, $k) as $index => $key) {
            if (in_array($key, $relevant, true)) {
                $dcg += 1.0 / log($index + 2, 2);
            }
        }

        $idcg = 0.0;
        $idealHits = min($k, count(array_unique($relevant)));
        for ($i = 0; $i < $idealHits; $i++) {
            $idcg += 1.0 / log($i + 2, 2);
        }

        return $idcg > 0 ? $dcg / $idcg : 0.0;
    }
}
