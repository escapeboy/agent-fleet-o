<?php

namespace App\Domain\GitRepository\Listeners;

use App\Domain\GitRepository\Jobs\PushContextToGitJob;
use App\Domain\GitRepository\Models\ContextGitSync;
use Illuminate\Support\Facades\Cache;

/**
 * Queues a context push when a team's artifacts or memory change.
 * Kanwas-inspired sprint.
 *
 * Debounce: at most one push per team per 60s. The sync lookup runs first (an
 * indexed point query on the unique team_id) so a sync configured during an
 * active debounce window is not skipped.
 */
class QueueContextGitPush
{
    public function handle(string $teamId): void
    {
        if ($teamId === '') {
            return;
        }

        $sync = ContextGitSync::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        if (! $sync) {
            return;
        }

        $debounceKey = "ctx-git-sync-debounce:{$teamId}";
        if (Cache::has($debounceKey)) {
            return;
        }
        Cache::put($debounceKey, true, now()->addSeconds(60));

        PushContextToGitJob::dispatch($sync->id);
    }
}
