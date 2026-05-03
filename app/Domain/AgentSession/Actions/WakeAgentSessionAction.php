<?php

namespace App\Domain\AgentSession\Actions;

use App\Domain\AgentSession\DTOs\SessionContext;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;

/**
 * Reconstitute a SessionContext for a fresh sandbox/container without
 * re-running any prior side effects. Records a Wake event in the log so
 * audit can see when the session was rehydrated.
 */
class WakeAgentSessionAction
{
    public function __construct(
        private readonly AppendSessionEventAction $append,
    ) {}

    public function execute(
        AgentSession $session,
        ?string $sandboxId = null,
        int $recentEventLimit = 50,
    ): SessionContext {
        $now = now();
        $session->update([
            'status' => $session->status?->isTerminal()
                ? $session->status
                : AgentSessionStatus::Active,
            'started_at' => $session->started_at ?? $now,
            'last_heartbeat_at' => $now,
            'last_known_sandbox_id' => $sandboxId ?? $session->last_known_sandbox_id,
        ]);

        $this->append->execute(
            session: $session->refresh(),
            kind: AgentSessionEventKind::Wake,
            payload: ['sandbox_id' => $sandboxId, 'at' => $now->toIso8601String()],
        );

        $session->refresh();

        $events = $session->events()
            ->orderByDesc('seq')
            ->take($recentEventLimit)
            ->get(['seq', 'kind', 'payload', 'created_at'])
            ->reverse()
            ->values()
            ->map(fn ($e) => [
                'seq' => (int) $e->seq,
                'kind' => $e->kind?->value,
                'payload' => $e->payload,
                'created_at' => $e->created_at?->toIso8601String(),
            ])
            ->all();

        return new SessionContext(
            session: $session,
            recentEvents: $events,
            workspaceContractSnapshot: $session->workspace_contract_snapshot,
            totalEventCount: (int) $session->events()->count(),
            lastSeq: $session->lastSeq(),
        );
    }
}
