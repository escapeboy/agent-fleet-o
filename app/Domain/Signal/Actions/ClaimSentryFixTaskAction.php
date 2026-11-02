<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Enums\SentryFixTaskState;
use App\Domain\Signal\Models\SentryFixTask;
use Illuminate\Support\Facades\DB;

/**
 * Atomically hands the oldest queued task (critical first) to a worker.
 */
class ClaimSentryFixTaskAction
{
    public const LEASE_HOURS = 3;

    /**
     * @param  list<string>  $excludeRepositories
     * @param  list<string>  $excludeTaskIds  tasks the worker wants to skip this time (e.g. snoozed)
     */
    public function execute(string $teamId, string $worker, array $excludeRepositories = [], array $excludeTaskIds = []): ?SentryFixTask
    {
        return DB::transaction(function () use ($teamId, $worker, $excludeRepositories, $excludeTaskIds): ?SentryFixTask {
            $query = SentryFixTask::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('state', SentryFixTaskState::Queued->value)
                ->when($excludeRepositories !== [], fn ($q) => $q->whereNotIn('repository', $excludeRepositories))
                ->when($excludeTaskIds !== [], fn ($q) => $q->whereNotIn('id', $excludeTaskIds))
                ->orderByDesc('is_critical')
                ->orderBy('created_at')
                ->orderBy('id');

            // Row locks: SKIP LOCKED lets concurrent claimers take different rows
            // on PostgreSQL; SQLite ignores lock clauses and relies on the guarded
            // UPDATE below.
            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lock('for update skip locked');
            } else {
                $query->lockForUpdate();
            }

            $task = $query->first();
            if ($task === null) {
                return null;
            }

            $task->appendEvent(SentryFixTaskState::Claimed, "Claimed by {$worker}.");

            $updated = SentryFixTask::withoutGlobalScopes()
                ->where('id', $task->id)
                ->where('state', SentryFixTaskState::Queued->value)
                ->update([
                    'state' => SentryFixTaskState::Claimed->value,
                    'claimed_by' => $worker,
                    'lease_expires_at' => now()->addHours(self::LEASE_HOURS),
                    'attempts' => $task->attempts + 1,
                    'events' => json_encode($task->events),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return null;
            }

            return SentryFixTask::withoutGlobalScopes()->find($task->id);
        });
    }
}
