<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Enums\SentryFixTaskState;
use App\Domain\Signal\Models\SentryFixTask;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues a fix task for an external fix worker. Idempotent: while a task for
 * the same (integration, Sentry issue) is still live, that task is returned
 * instead of creating a second one.
 */
class CreateSentryFixTaskAction
{
    /**
     * @param  array<string, mixed>  $attributes  Fillable SentryFixTask attributes (team_id, sentry_issue_id, repository, title required).
     * @return array{0: SentryFixTask, 1: bool} The task and whether it was newly created.
     */
    public function execute(array $attributes): array
    {
        $existing = $this->findLive($attributes['team_id'], $attributes['integration_id'] ?? null, $attributes['sentry_issue_id']);
        if ($existing !== null) {
            return [$existing, false];
        }

        $task = new SentryFixTask(array_merge($attributes, [
            'state' => SentryFixTaskState::Queued,
            'attempts' => 0,
        ]));
        $task->appendEvent(SentryFixTaskState::Queued, 'Queued for external fix worker.');

        try {
            // Savepoint: on PostgreSQL a failed insert aborts the surrounding transaction.
            DB::transaction(fn () => $task->save());
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent triage of the same issue.
            $existing = $this->findLive($attributes['team_id'], $attributes['integration_id'] ?? null, $attributes['sentry_issue_id']);
            if ($existing !== null) {
                return [$existing, false];
            }
            throw new \RuntimeException('Sentry fix task insert conflicted but no live task was found.');
        }

        return [$task, true];
    }

    protected function findLive(string $teamId, ?string $integrationId, string $sentryIssueId): ?SentryFixTask
    {
        return SentryFixTask::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('integration_id', $integrationId)
            ->where('sentry_issue_id', $sentryIssueId)
            ->whereNotIn('state', SentryFixTaskState::terminalValues())
            ->first();
    }
}
