<?php

namespace App\Console\Commands;

use App\Domain\Signal\Enums\SentryFixTaskState;
use App\Domain\Signal\Models\SentryFixTask;
use Illuminate\Console\Command;

/**
 * Recovers sentry fix tasks whose worker lease expired (worker crashed or
 * vanished). Tasks waiting for a person (awaiting_approval) or for pick-up
 * (queued, approved) carry no lease and are never touched.
 */
class ReapSentryFixTasks extends Command
{
    /** A task that has had this many attempts is failed instead of re-queued. */
    private const MAX_ATTEMPTS = 2;

    protected $signature = 'sentry-fix-tasks:reap';

    protected $description = 'Re-queue or fail Sentry fix tasks whose worker lease has expired.';

    public function handle(): int
    {
        $requeued = 0;
        $failed = 0;
        $escalated = 0;

        $expired = SentryFixTask::withoutGlobalScopes()
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->whereIn('state', [
                SentryFixTaskState::Claimed->value,
                SentryFixTaskState::Fixing->value,
                SentryFixTaskState::PrOpen->value,
                SentryFixTaskState::Merging->value,
                SentryFixTaskState::Deploying->value,
                SentryFixTaskState::Watching->value,
            ])
            ->get();

        foreach ($expired as $task) {
            if (in_array($task->state, [SentryFixTaskState::Merging, SentryFixTaskState::Deploying, SentryFixTaskState::Watching], true)) {
                // The change may already be merged; re-queueing would start a second fix.
                $this->close($task, SentryFixTaskState::Escalated, 'Lease expired after merge started; needs manual check.');
                $escalated++;
            } elseif ($task->attempts < self::MAX_ATTEMPTS) {
                $task->claimed_by = null;
                $this->close($task, SentryFixTaskState::Queued, 'Lease expired; re-queued.');
                $requeued++;
            } else {
                $this->close($task, SentryFixTaskState::Failed, 'Lease expired after '.$task->attempts.' attempts.');
                $failed++;
            }
        }

        $this->info("Reaped: {$requeued} re-queued, {$failed} failed, {$escalated} escalated.");

        return self::SUCCESS;
    }

    private function close(SentryFixTask $task, SentryFixTaskState $state, string $message): void
    {
        $task->state = $state;
        $task->lease_expires_at = null;
        $task->appendEvent($state, $message);
        $task->save();
    }
}
