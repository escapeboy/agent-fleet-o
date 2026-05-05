<?php

namespace App\Domain\AgentSession\Actions;

use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;

/**
 * Detach an agent session from its current sandbox cleanly. Sandbox can
 * die after this without losing run state — wake() will rehydrate later.
 */
class SleepAgentSessionAction
{
    public function __construct(
        private readonly AppendSessionEventAction $append,
    ) {}

    public function execute(AgentSession $session, ?string $reason = null): AgentSession
    {
        if ($session->status?->isTerminal()) {
            return $session;
        }

        $session->update([
            'status' => AgentSessionStatus::Sleeping,
            'last_heartbeat_at' => now(),
        ]);

        $this->append->execute(
            session: $session->refresh(),
            kind: AgentSessionEventKind::Sleep,
            payload: ['reason' => $reason, 'at' => now()->toIso8601String()],
        );

        return $session->refresh();
    }
}
