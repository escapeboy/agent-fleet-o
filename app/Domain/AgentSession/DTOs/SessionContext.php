<?php

namespace App\Domain\AgentSession\DTOs;

use App\Domain\AgentSession\Models\AgentSession;

/**
 * Reconstituted view of an AgentSession ready for a fresh sandbox to wake
 * into. Returned by WakeAgentSessionAction.
 */
final readonly class SessionContext
{
    public function __construct(
        public AgentSession $session,
        /** @var array<int, array<string, mixed>> */
        public array $recentEvents,
        /** @var array<string, mixed>|null */
        public ?array $workspaceContractSnapshot,
        public int $totalEventCount,
        public int $lastSeq,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->session->id,
            'status' => $this->session->status?->value,
            'team_id' => $this->session->team_id,
            'agent_id' => $this->session->agent_id,
            'experiment_id' => $this->session->experiment_id,
            'crew_execution_id' => $this->session->crew_execution_id,
            'last_seq' => $this->lastSeq,
            'total_event_count' => $this->totalEventCount,
            'workspace_contract_snapshot' => $this->workspaceContractSnapshot,
            'recent_events' => $this->recentEvents,
        ];
    }
}
