<?php

namespace App\Domain\AgentSession\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Hand off an active or sleeping session from one agent to another within the
 * same team. Source is paused (Sleeping); a new target session is created
 * carrying the workspace contract snapshot forward. Both sides emit a single
 * mirrored event so audit trails on either session reconstruct the move.
 *
 * Idempotent within a 60-second window: if the source's metadata already
 * records a handoff to the same target agent newer than 60s ago, return the
 * existing target session rather than creating a duplicate.
 */
class HandoffAgentSessionAction
{
    private const IDEMPOTENCY_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly AppendSessionEventAction $append,
    ) {}

    /**
     * @return array{source: AgentSession, target: AgentSession, reused: bool}
     */
    public function execute(
        AgentSession $source,
        string $targetAgentId,
        ?string $note = null,
    ): array {
        if ($source->status === AgentSessionStatus::Pending) {
            throw new RuntimeException('Cannot hand off a pending session — start it first.');
        }
        if ($source->status->isTerminal()) {
            throw new RuntimeException("Source session is in terminal status '{$source->status->value}'.");
        }

        $targetAgent = Agent::withoutGlobalScopes()->find($targetAgentId);
        if (! $targetAgent) {
            throw new InvalidArgumentException('Target agent not found.');
        }
        if ($targetAgent->team_id !== $source->team_id) {
            throw new InvalidArgumentException('Cross-team handoff is not allowed.');
        }
        if ($targetAgent->status === AgentStatus::Disabled) {
            throw new InvalidArgumentException('Target agent is disabled.');
        }
        if ($targetAgent->id === $source->agent_id) {
            throw new InvalidArgumentException('Target agent must differ from source agent.');
        }

        $existing = $this->findRecentIdempotentHandoff($source, $targetAgentId);
        if ($existing !== null) {
            return ['source' => $source, 'target' => $existing, 'reused' => true];
        }

        return DB::transaction(function () use ($source, $targetAgent, $note): array {
            /** @var AgentSession $target */
            $target = AgentSession::create([
                'team_id' => $source->team_id,
                'agent_id' => $targetAgent->id,
                'experiment_id' => $source->experiment_id,
                'crew_execution_id' => $source->crew_execution_id,
                'user_id' => $source->user_id,
                'status' => AgentSessionStatus::Pending,
                'workspace_contract_snapshot' => $source->workspace_contract_snapshot,
                'metadata' => [
                    'handoff' => [
                        'from_session_id' => $source->id,
                        'from_agent_id' => $source->agent_id,
                        'note' => $note,
                        'received_at' => Carbon::now()->toIso8601String(),
                    ],
                ],
            ]);

            $this->append->execute(
                session: $source,
                kind: AgentSessionEventKind::HandoffOut,
                payload: [
                    'target_session_id' => $target->id,
                    'target_agent_id' => $targetAgent->id,
                    'note' => $note,
                ],
            );

            $this->append->execute(
                session: $target->refresh(),
                kind: AgentSessionEventKind::HandoffIn,
                payload: [
                    'source_session_id' => $source->id,
                    'source_agent_id' => $source->agent_id,
                    'note' => $note,
                ],
            );

            $sourceMeta = is_array($source->metadata) ? $source->metadata : [];
            $sourceMeta['handoff'] = [
                'target_session_id' => $target->id,
                'target_agent_id' => $targetAgent->id,
                'note' => $note,
                'created_at' => Carbon::now()->toIso8601String(),
            ];
            $source->update([
                'metadata' => $sourceMeta,
                'status' => AgentSessionStatus::Sleeping,
            ]);

            return ['source' => $source->refresh(), 'target' => $target->refresh(), 'reused' => false];
        });
    }

    private function findRecentIdempotentHandoff(AgentSession $source, string $targetAgentId): ?AgentSession
    {
        $metadata = is_array($source->metadata) ? $source->metadata : null;
        $handoff = $metadata['handoff'] ?? null;
        if (! is_array($handoff)) {
            return null;
        }
        if (($handoff['target_agent_id'] ?? null) !== $targetAgentId) {
            return null;
        }
        $createdAt = $handoff['created_at'] ?? null;
        if (! is_string($createdAt) || $createdAt === '') {
            return null;
        }
        try {
            $created = Carbon::parse($createdAt);
        } catch (\Throwable) {
            return null;
        }
        if ($created->diffInSeconds(Carbon::now()) > self::IDEMPOTENCY_WINDOW_SECONDS) {
            return null;
        }
        $targetSessionId = $handoff['target_session_id'] ?? null;
        if (! is_string($targetSessionId) || $targetSessionId === '') {
            return null;
        }

        return AgentSession::withoutGlobalScopes()
            ->where('team_id', $source->team_id)
            ->find($targetSessionId);
    }
}
