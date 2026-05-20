<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Enums\MemoryBeliefStatus;
use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Facades\DB;

/**
 * Resolve a contradiction flagged by {@see DetectMemoryContradictionsAction}.
 *
 * Two outcomes:
 *  - "supersede" — the passed memory is the one to keep; its conflicting
 *    partner is marked Superseded and linked via supersedes_id, joining the
 *    RoBrain temporal belief graph.
 *  - "dismiss" — the pair was a false positive; the flag is simply cleared.
 *
 * Either way the conflict flag is cleared on both rows.
 */
class ResolveMemoryConflictAction
{
    public const RESOLUTION_SUPERSEDE = 'supersede';

    public const RESOLUTION_DISMISS = 'dismiss';

    /**
     * @param  string  $memoryId  The memory to KEEP.
     * @param  string  $resolution  One of RESOLUTION_SUPERSEDE | RESOLUTION_DISMISS.
     *
     * @throws \InvalidArgumentException
     */
    public function execute(string $memoryId, string $teamId, string $resolution): Memory
    {
        if (! in_array($resolution, [self::RESOLUTION_SUPERSEDE, self::RESOLUTION_DISMISS], true)) {
            throw new \InvalidArgumentException("Unknown conflict resolution: {$resolution}");
        }

        $memory = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($memoryId);

        if (! $memory) {
            throw new \InvalidArgumentException('Memory not found.');
        }

        if (! $memory->conflict_flag) {
            throw new \InvalidArgumentException('Memory is not flagged as conflicting.');
        }

        $partner = $memory->conflict_with_id
            ? Memory::withoutGlobalScopes()->where('team_id', $teamId)->find($memory->conflict_with_id)
            : null;

        DB::transaction(function () use ($memory, $partner, $resolution) {
            if ($resolution === self::RESOLUTION_SUPERSEDE && $partner) {
                // The kept memory replaces its partner — the partner stays
                // queryable for audit but is never injected again.
                $partner->belief_status = MemoryBeliefStatus::Superseded;
                $memory->supersedes_id ??= $partner->id;
            }

            foreach (array_filter([$memory, $partner]) as $row) {
                $row->conflict_flag = false;
                $row->conflict_with_id = null;
                $row->conflict_detected_at = null;
                $row->save();
            }
        });

        return $memory->fresh();
    }
}
