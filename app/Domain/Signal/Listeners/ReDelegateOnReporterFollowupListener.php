<?php

namespace App\Domain\Signal\Listeners;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Actions\DelegateBugReportToAgentAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalCommentAdded;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * When a reporter posts a follow-up comment on a bug-report Signal whose
 * previous agent attempt has reached `Review` (or is still actively fixing),
 * re-engage the agent loop with the new context.
 *
 * Idempotent: a system comment per reporter follow-up is keyed on the
 * triggering comment id, so re-fires of this listener can't post duplicates.
 */
class ReDelegateOnReporterFollowupListener
{
    public function __construct(
        private readonly DelegateBugReportToAgentAction $delegate,
        private readonly AddSignalCommentAction $addComment,
    ) {}

    public function handle(SignalCommentAdded $event): void
    {
        $comment = $event->comment;

        if ($comment->author_type !== CommentAuthorType::Reporter->value) {
            return;
        }

        // Re-fetch the signal without team scope — listener may run in a queue
        // worker context where the global scope can't be relied on.
        $signal = $comment->signal()->withoutGlobalScopes()->first();

        if ($signal === null
            || $signal->source_type !== 'bug_report'
            || $signal->experiment_id === null) {
            return;
        }

        // Only re-engage when the prior loop has reached Review or is still
        // actively fixing. If the bug is Resolved/Dismissed, do not resurrect.
        if (! in_array(
            $signal->status,
            [SignalStatus::Review, SignalStatus::AgentFixing, SignalStatus::DelegatedToAgent],
            true,
        )) {
            return;
        }

        $previousExperiment = Experiment::withoutGlobalScopes()->find($signal->experiment_id);
        if ($previousExperiment === null) {
            return;
        }

        $actor = $this->resolveActor($previousExperiment, (string) $signal->team_id);
        if ($actor === null) {
            Log::warning('ReDelegateOnReporterFollowupListener: no actor available — skipping', [
                'signal_id' => $signal->id,
                'previous_experiment_id' => $previousExperiment->id,
            ]);

            return;
        }

        $additionalContext = sprintf(
            "Reporter posted a follow-up after the previous agent attempt.\n\n".
                "Reporter follow-up:\n%s\n\n".
                "Previous agent attempt: experiment %s (status: %s).\n".
                'See the **Reporter Feedback & Team Notes** block above for the full comment trail.',
            $comment->body,
            $previousExperiment->id,
            $previousExperiment->status->value,
        );

        try {
            $newExperiment = $this->delegate->execute(
                signal: $signal,
                actor: $actor,
                agentId: $previousExperiment->agent_id,
                additionalContext: $additionalContext,
            );
        } catch (\Throwable $e) {
            Log::error('ReDelegateOnReporterFollowupListener: re-delegation failed', [
                'signal_id' => $signal->id,
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $this->addComment->execute(
                signal: $signal,
                body: "Re-delegated to agent based on reporter follow-up (experiment: {$newExperiment->id}).",
                authorType: CommentAuthorType::Agent,
                idempotencyKey: 'reporter-followup:'.$comment->id,
            );
        } catch (\Throwable $e) {
            Log::warning('ReDelegateOnReporterFollowupListener: system comment failed (re-delegation already succeeded)', [
                'signal_id' => $signal->id,
                'new_experiment_id' => $newExperiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveActor(Experiment $experiment, string $teamId): ?User
    {
        if ($experiment->user_id !== null) {
            $previousActor = User::find($experiment->user_id);
            if ($previousActor !== null) {
                return $previousActor;
            }
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        if ($team !== null && $team->owner_id !== null) {
            return User::find($team->owner_id);
        }

        return null;
    }
}
