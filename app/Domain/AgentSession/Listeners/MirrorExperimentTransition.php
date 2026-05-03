<?php

namespace App\Domain\AgentSession\Listeners;

use App\Domain\AgentSession\Actions\AppendSessionEventAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Events\ExperimentTransitioned;

/**
 * Funnels existing ExperimentTransitioned events into the AgentSession
 * event log without changing the Experiment domain. Zero-touch mirror.
 *
 * Skips silently when the experiment has no associated AgentSession yet
 * — sessions are opt-in for v1, not retrofitted to every experiment.
 */
class MirrorExperimentTransition
{
    public function __construct(
        private readonly AppendSessionEventAction $append,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        $session = AgentSession::query()
            ->where('experiment_id', $event->experiment->id)
            ->whereIn('status', ['pending', 'active', 'sleeping'])
            ->latest('created_at')
            ->first();

        if (! $session) {
            return;
        }

        $this->append->execute(
            session: $session,
            kind: AgentSessionEventKind::Transition,
            payload: [
                'experiment_id' => $event->experiment->id,
                'from_state' => $event->fromState->value,
                'to_state' => $event->toState->value,
            ],
        );
    }
}
