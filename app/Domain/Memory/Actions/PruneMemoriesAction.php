<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Facades\Log;

class PruneMemoriesAction
{
    /**
     * Delete memories older than the configured TTL.
     *
     * @return int Number of deleted memories
     */
    public function execute(?int $ttlDays = null): int
    {
        $ttlDays = $ttlDays ?? config('memory.ttl_days', 90);

        if ($ttlDays <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($ttlDays);

        $count = Memory::withoutGlobalScopes()
            ->where('created_at', '<', $cutoff)
            ->delete();

        if ($count > 0) {
            Log::info("PruneMemoriesAction: Pruned {$count} memories older than {$ttlDays} days");
        }

        return $count;
    }
}
