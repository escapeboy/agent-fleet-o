<?php

namespace App\Domain\Workflow\Listeners;

use App\Domain\Workflow\Events\WorkflowSaved;
use App\Domain\Workflow\Jobs\PushWorkflowYamlJob;
use App\Domain\Workflow\Models\WorkflowGitSync;
use Illuminate\Support\Facades\Cache;

/**
 * Listener that queues a YAML push to the linked Git repository when a Workflow saves.
 * Build #5, Trendshift top-5 sprint.
 *
 * Debounce: 1 push per workflow per minute via cache(). Multiple saves in quick
 * succession only result in a single deferred push.
 */
class QueueWorkflowYamlPush
{
    public function handle(WorkflowSaved $event): void
    {
        $sync = WorkflowGitSync::withoutGlobalScopes()
            ->where('workflow_id', $event->workflow->id)
            ->first();

        if (! $sync) {
            return;
        }

        // Debounce: only one push per workflow per 60s.
        $debounceKey = "wf-git-sync-debounce:{$sync->id}";
        if (Cache::has($debounceKey)) {
            return;
        }
        Cache::put($debounceKey, true, now()->addSeconds(60));

        PushWorkflowYamlJob::dispatch($sync->id);
    }
}
