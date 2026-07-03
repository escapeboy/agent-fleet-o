<?php

namespace App\Domain\AgentSession\Listeners;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;

/**
 * Closes an experiment's open AgentSession when the run reaches a terminal or
 * failed state, mapping the experiment outcome onto the session status.
 *
 * MUST be registered AFTER MirrorExperimentTransition: the final transition has
 * to be mirrored into the event log while the session is still open (Mirror
 * filters on open statuses), before this listener flips it closed.
 *
 * Failed states are retryable in the experiment machine (not in terminalStates),
 * so a failed→retry→Building flow leaves this closed session as the record of
 * that attempt and OpenAgentSessionOnExecution opens a fresh one for the retry.
 */
class CloseAgentSessionOnTerminal
{
    private const OPEN_STATUSES = [
        AgentSessionStatus::Pending->value,
        AgentSessionStatus::Active->value,
        AgentSessionStatus::Sleeping->value,
    ];

    public function handle(ExperimentTransitioned $event): void
    {
        $target = $this->mapStatus($event->toState);

        if ($target === null) {
            return;
        }

        $experiment = $event->experiment;

        $session = AgentSession::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->where('experiment_id', $experiment->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->latest('created_at')
            ->first();

        if (! $session) {
            return;
        }

        $session->update([
            'status' => $target,
            'ended_at' => now(),
        ]);
    }

    private function mapStatus(ExperimentStatus $state): ?AgentSessionStatus
    {
        return match ($state) {
            ExperimentStatus::Completed => AgentSessionStatus::Completed,
            ExperimentStatus::Killed,
            ExperimentStatus::Discarded,
            ExperimentStatus::Expired => AgentSessionStatus::Cancelled,
            ExperimentStatus::ScoringFailed,
            ExperimentStatus::PlanningFailed,
            ExperimentStatus::BuildingFailed,
            ExperimentStatus::ExecutionFailed => AgentSessionStatus::Failed,
            default => null,
        };
    }
}
