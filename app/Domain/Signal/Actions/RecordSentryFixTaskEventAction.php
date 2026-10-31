<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Enums\SentryFixTaskState;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Exceptions\InvalidSentryFixTaskTransitionException;
use App\Domain\Signal\Models\SentryFixTask;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SignalStatusTransitionMap;
use Illuminate\Support\Facades\DB;

/**
 * Applies a worker-reported state change: validates it against the transition
 * table, appends it to the task trail, refreshes the lease, and resolves the
 * signal when the task reaches `done`.
 */
class RecordSentryFixTaskEventAction
{
    public function __construct(
        private readonly UpdateSignalStatusAction $updateSignalStatus,
        private readonly SignalStatusTransitionMap $signalTransitions,
    ) {}

    /**
     * @throws InvalidSentryFixTaskTransitionException
     */
    public function execute(SentryFixTask $task, SentryFixTaskState $newState, string $message, ?string $prUrl = null): SentryFixTask
    {
        return DB::transaction(function () use ($task, $newState, $message, $prUrl): SentryFixTask {
            // Re-read under a row lock so two workers cannot both pass the check.
            $fresh = SentryFixTask::withoutGlobalScopes()->lockForUpdate()->findOrFail($task->id);

            if (! $fresh->state->canTransitionTo($newState)) {
                throw new InvalidSentryFixTaskTransitionException(
                    "Cannot transition sentry fix task from {$fresh->state->value} to {$newState->value}.",
                );
            }

            $fresh->state = $newState;
            $fresh->appendEvent($newState, $message);
            if ($prUrl !== null) {
                $fresh->pr_url = $prUrl;
            }

            if ($newState === SentryFixTaskState::Queued) {
                $fresh->claimed_by = null;
            }
            $fresh->lease_expires_at = $newState->isLeaseFree()
                ? null
                : now()->addHours(ClaimSentryFixTaskAction::LEASE_HOURS);

            $fresh->save();

            if ($newState === SentryFixTaskState::Done) {
                $this->resolveSignal($fresh);
            }

            return $fresh;
        });
    }

    private function resolveSignal(SentryFixTask $task): void
    {
        if ($task->signal_id === null) {
            return;
        }

        $signal = Signal::withoutGlobalScopes()->find($task->signal_id);
        if ($signal === null || $signal->status === SignalStatus::Resolved) {
            return;
        }

        if ($this->signalTransitions->canTransition($signal->status ?? SignalStatus::Received, SignalStatus::Resolved)) {
            $this->updateSignalStatus->execute($signal, SignalStatus::Resolved);
        } else {
            $signal->update(['status' => SignalStatus::Resolved]);
        }
    }
}
