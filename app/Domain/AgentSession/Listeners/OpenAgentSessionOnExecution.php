<?php

namespace App\Domain\AgentSession\Listeners;

use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Events\ExperimentTransitioned;

/**
 * Opens an AgentSession when an agent-driven experiment starts real work.
 *
 * Without this, CreateAgentSessionAction has no caller and /agent-sessions is
 * always empty. Scope is limited to tracks where a long-running agent actually
 * does the work (debug/warm-build, workflow DAG, web build) so the session list
 * stays signal — non-agent tracks (growth/retention/…) get no session.
 *
 * MUST be registered BEFORE MirrorExperimentTransition so the very transition
 * that opens the session is itself mirrored into the session's event log.
 */
class OpenAgentSessionOnExecution
{
    /** Tracks whose runs are driven by a long-running agent worth a session. */
    private const AGENT_TRACKS = [
        ExperimentTrack::Debug,
        ExperimentTrack::Workflow,
        ExperimentTrack::WebBuild,
    ];

    /** A session opens when the run first enters one of these states. */
    private const OPENING_STATES = [
        ExperimentStatus::Building,
        ExperimentStatus::Executing,
    ];

    public function __construct(
        private readonly CreateAgentSessionAction $create,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        $experiment = $event->experiment;

        if (! in_array($experiment->track, self::AGENT_TRACKS, true)) {
            return;
        }

        if (! in_array($event->toState, self::OPENING_STATES, true)) {
            return;
        }

        // One open session per experiment. Building-then-Executing, or a retry
        // re-entering the pipeline, must not spawn a second. Team-explicit +
        // scope-free so the check is correct regardless of ambient team context.
        $alreadyOpen = AgentSession::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->where('experiment_id', $experiment->id)
            ->whereIn('status', [
                AgentSessionStatus::Pending->value,
                AgentSessionStatus::Active->value,
                AgentSessionStatus::Sleeping->value,
            ])
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        $session = $this->create->execute(
            teamId: $experiment->team_id,
            agentId: $experiment->agent_id,
            experimentId: $experiment->id,
            metadata: [
                'track' => $experiment->track->value,
                'opened_on_state' => $event->toState->value,
            ],
        );

        $session->update([
            'status' => AgentSessionStatus::Active,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }
}
