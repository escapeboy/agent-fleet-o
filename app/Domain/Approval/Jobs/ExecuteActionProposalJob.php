<?php

namespace App\Domain\Approval\Jobs;

use App\Domain\Approval\Enums\ActionProposalStatus;
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

        $actor = $this->resolveActor($proposal);
        if (! $actor) {
            $this->markFailed($proposal, 'Actor user could not be resolved (no actor_user_id and no team owner).');

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
        } catch (Throwable $e) {
            $this->markFailed($proposal, $e->getMessage());
            Log::warning('ExecuteActionProposalJob: executor threw', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
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
