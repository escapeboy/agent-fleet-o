<?php

namespace App\Domain\AgentSession\Actions;

use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;

class CancelAgentSessionAction
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
            'status' => AgentSessionStatus::Cancelled,
            'ended_at' => now(),
        ]);

        $this->append->execute(
            session: $session->refresh(),
            kind: AgentSessionEventKind::Note,
            payload: ['cancelled' => true, 'reason' => $reason],
        );

        return $session->refresh();
    }
}
