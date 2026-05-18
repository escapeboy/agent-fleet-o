<?php

namespace App\Domain\GitRepository\Listeners;

use App\Domain\GitRepository\Jobs\PushContextToGitJob;
use App\Domain\GitRepository\Models\ContextGitSync;
use Illuminate\Support\Facades\Cache;

/**
 * Queues a context push when a team's artifacts or memory change.
 * Kanwas-inspired sprint.
 *
 * Debounce: at most one push per team per 60s. The debounce key is set before
 * the sync lookup so teams without a configured sync incur at most one DB
 * query per minute regardless of write volume.
 */
class QueueContextGitPush
{
    public function handle(string $teamId): void
    {
        if ($teamId === '') {
            return;
        }

        $debounceKey = "ctx-git-sync-debounce:{$teamId}";
        if (Cache::has($debounceKey)) {
            return;
        }
        Cache::put($debounceKey, true, now()->addSeconds(60));

        $sync = ContextGitSync::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        if (! $sync) {
            return;
        }

        PushContextToGitJob::dispatch($sync->id);
    }
}
