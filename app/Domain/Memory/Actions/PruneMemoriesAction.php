<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Facades\Log;

class PruneMemoriesAction
{
    /**
     * Prune memories using importance-weighted scoring.
     *
     * Prune score = recency_decay * effective_importance
     * High-importance or frequently-retrieved memories are protected.
     * An absolute max TTL serves as a safety net.
     *
     * @return int Number of deleted memories
     */
    public function execute(?int $ttlDays = null): int
    {
        $ttlDays = $ttlDays ?? config('memory.ttl_days', 90);

        if ($ttlDays <= 0) {
            return 0;
        }

        $scoreThreshold = config('memory.pruning.score_threshold', 0.05);
        $maxTtlDays = config('memory.pruning.max_ttl_days', 365);
        $protectImportance = config('memory.pruning.protect_importance_above', 0.8);
        $protectRetrieval = config('memory.pruning.protect_retrieval_above', 10);
        $halfLifeDays = config('memory.scoring.half_life_days', 7);

        $maxCutoff = now()->subDays($maxTtlDays);
        $ttlCutoff = now()->subDays($ttlDays);

        $deleted = 0;

        // Tier 1: Absolute max TTL — delete very old memories regardless (safety net)
        // But still protect high-value ones
        $deleted += Memory::withoutGlobalScopes()
            ->where('created_at', '<', $maxCutoff)
            ->where(function ($q) use ($protectImportance, $protectRetrieval) {
                $q->where('importance', '<', $protectImportance)
                    ->where(function ($sub) use ($protectRetrieval) {
                        $sub->where('retrieval_count', '<', $protectRetrieval)
                            ->orWhereNull('retrieval_count');
                    });
            })
            ->delete();

        // Tier 2: Score-based pruning for memories older than standard TTL
        // prune_score = recency_decay * effective_importance
        // effective_importance = LEAST(importance + LN(1 + retrieval_count) * 0.15, 1.0)
        $pruneScoreSql = <<<'SQL'
            POWER(0.5, EXTRACT(EPOCH FROM (NOW() - COALESCE(last_accessed_at, created_at))) / 86400.0 / ?)
            * LEAST(COALESCE(importance, 0.5) + LN(1 + COALESCE(retrieval_count, 0)) * 0.15, 1.0)
        SQL;

        $deleted += Memory::withoutGlobalScopes()
            ->where('created_at', '<', $ttlCutoff)
            ->whereRaw("({$pruneScoreSql}) < ?", [$halfLifeDays, $scoreThreshold])
            ->where(function ($q) use ($protectImportance, $protectRetrieval) {
                $q->where('importance', '<', $protectImportance)
                    ->where(function ($sub) use ($protectRetrieval) {
                        $sub->where('retrieval_count', '<', $protectRetrieval)
                            ->orWhereNull('retrieval_count');
                    });
            })
            ->delete();

        if ($deleted > 0) {
            Log::info("PruneMemoriesAction: Pruned {$deleted} memories (score < {$scoreThreshold}, ttl={$ttlDays}d, max={$maxTtlDays}d)");
        }

        return $deleted;
    }
}
