<?php

namespace App\Domain\Approval\Jobs;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Events\ActionProposalExecuted;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Services\ActionProposalExecutor;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteActionProposalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $proposalId)
    {
        $this->onQueue('default');
    }

    public function handle(ActionProposalExecutor $executor): void
    {
        $proposal = ActionProposal::query()
            ->withoutGlobalScopes()
            ->find($this->proposalId);

        if (! $proposal) {
            Log::warning('ExecuteActionProposalJob: proposal not found', ['proposal_id' => $this->proposalId]);

            return;
        }

        // Idempotency: only proceed if approved + not yet executed.
        if ($proposal->status !== ActionProposalStatus::Approved || $proposal->executed_at !== null) {
            Log::info('ExecuteActionProposalJob: skipping non-approved or already-executed proposal', [
                'proposal_id' => $proposal->id,
                'status' => $proposal->status->value,
                'executed_at' => $proposal->executed_at?->toIso8601String(),
            ]);

            return;
        }

        // Approval is granted against a deadline; the queue can drain after it.
        // Re-check at the execution seam so a proposal approved just before
        // expiry can never produce a side effect once the window has closed.
        if ($proposal->expires_at !== null && $proposal->expires_at->isPast()) {
            $proposal->update([
                'status' => ActionProposalStatus::Expired,
                'execution_error' => 'Approval window closed before the queued execution ran.',
            ]);

            Log::info('ExecuteActionProposalJob: refusing to execute past expiry', [
                'proposal_id' => $proposal->id,
                'expires_at' => $proposal->getAttribute('expires_at'),
            ]);

            return;
        }

        $actor = $this->resolveActor($proposal);
        if (! $actor) {
            $this->markFailed($proposal, 'Actor user could not be resolved (no actor_user_id and no team owner).');

            return;
        }

        // Claim the proposal atomically. Two jobs can be queued for one proposal
        // (a human approval racing a timeout "allow"); only one may run the action.
        $claimed = ActionProposal::query()
            ->withoutGlobalScopes()
            ->whereKey($proposal->id)
            ->where('status', ActionProposalStatus::Approved->value)
            ->whereNull('executed_at')
            ->update(['executed_at' => now()]);

        if ($claimed !== 1) {
            Log::info('ExecuteActionProposalJob: proposal already claimed by another job', ['proposal_id' => $proposal->id]);

            return;
        }

        try {
            $result = $executor->execute($proposal, $actor);

            $proposal->update([
                'status' => ActionProposalStatus::Executed,
                'executed_at' => now(),
                'execution_result' => $result,
                'execution_error' => null,
            ]);

            ActionProposalExecuted::dispatch($proposal->refresh(), true);
        } catch (Throwable $e) {
            $this->markFailed($proposal, $e->getMessage());
            Log::warning('ExecuteActionProposalJob: executor threw', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);

            ActionProposalExecuted::dispatch($proposal->refresh(), false);
        }
    }

    /**
     * Called by the queue when the job dies without finishing (timeout, worker
     * killed, max attempts). The claim already set executed_at, so without this the
     * row would stay "approved" with an execution time and look like it had run.
     */
    public function failed(?Throwable $e): void
    {
        $updated = ActionProposal::query()
            ->withoutGlobalScopes()
            ->whereKey($this->proposalId)
            ->where('status', ActionProposalStatus::Approved->value)
            ->update([
                'status' => ActionProposalStatus::ExecutionFailed->value,
                'execution_error' => mb_substr('Execution job did not finish: '.($e ? class_basename($e) : 'unknown failure'), 0, 1000),
            ]);

        if ($updated === 1) {
            Log::warning('ExecuteActionProposalJob: job failed before the proposal was settled', [
                'proposal_id' => $this->proposalId,
                'exception' => $e ? $e::class : null,
            ]);
        }
    }

    private function resolveActor(ActionProposal $proposal): ?User
    {
        if ($proposal->actor_user_id) {
            $actor = User::find($proposal->actor_user_id);
            if ($actor) {
                if ($actor->current_team_id !== $proposal->team_id) {
                    $actor->current_team_id = $proposal->team_id;
                }

                return $actor;
            }
        }

        $team = Team::find($proposal->team_id);
        if ($team && $team->owner_id) {
            $owner = User::find($team->owner_id);
            if ($owner) {
                if ($owner->current_team_id !== $proposal->team_id) {
                    $owner->current_team_id = $proposal->team_id;
                }

                return $owner;
            }
        }

        return null;
    }

    private function markFailed(ActionProposal $proposal, string $error): void
    {
        $proposal->update([
            'status' => ActionProposalStatus::ExecutionFailed,
            'executed_at' => now(),
            'execution_error' => mb_substr($error, 0, 1000),
        ]);
    }
}
