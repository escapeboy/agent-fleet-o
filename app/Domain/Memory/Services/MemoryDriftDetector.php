<?php

namespace App\Domain\Memory\Services;

use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Facades\DB;

/**
 * Detect memory drift — when a fact's current `embedding` has diverged
 * from its `embedding_at_creation` by more than a configured cosine
 * distance threshold.
 *
 * Drift is a signal that the meaning of a stored fact has changed since
 * the agent first wrote it. High drift on a high-importance fact suggests
 * that downstream reasoning may be working from a stale version of truth.
 *
 * Threshold is a heuristic: 0.30 cosine distance (=70% similarity) is
 * outside typical intra-cluster variance for OpenAI ada-002 / text-embedding-3.
 * Operators can override via config('memory.drift_threshold').
 */
class MemoryDriftDetector
{
    public function threshold(): float
    {
        $value = config('memory.drift_threshold');

        return is_numeric($value) ? (float) $value : 0.30;
    }

    /**
     * Memories above the drift threshold for the given team.
     *
     * @return array<int, array{memory_id: string, drift_score: float, last_updated_at: string|null}>
     */
    public function detectForTeam(string $teamId): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $threshold = $this->threshold();

        // Cosine distance via pgvector <=> operator. Memories where embedding_at_creation
        // is null are skipped (legacy rows; drift not measurable).
        $rows = DB::table('memories')
            ->select('id as memory_id', 'updated_at')
            ->selectRaw('(embedding <=> embedding_at_creation) AS drift_score')
            ->where('team_id', $teamId)
            ->whereNotNull('embedding')
            ->whereNotNull('embedding_at_creation')
            ->whereRaw('(embedding <=> embedding_at_creation) > ?', [$threshold])
            ->orderByDesc('drift_score')
            ->limit(500)
            ->get();

        return $rows->map(fn ($r) => [
            'memory_id' => (string) $r->memory_id,
            'drift_score' => (float) $r->drift_score,
            'last_updated_at' => $r->updated_at instanceof \DateTimeInterface
                ? $r->updated_at->format(DATE_ATOM)
                : (is_string($r->updated_at) ? $r->updated_at : null),
        ])->all();
    }

    /**
     * Snapshot the current embedding into embedding_at_creation when missing.
     * Called by listeners on Memory creation; a no-op when the column is
     * already populated.
     */
    public function snapshotIfMissing(Memory $memory): void
    {
        if ($memory->getAttribute('embedding_at_creation') !== null) {
            return;
        }
        if ($memory->getAttribute('embedding') === null) {
            return;
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('memories')
            ->where('id', $memory->id)
            ->update(['embedding_at_creation' => DB::raw('embedding')]);
    }
}
