<?php

namespace App\Domain\AgentSession\DTOs;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use Carbon\Carbon;

/**
 * Result of replaying an AgentSession's event log. Carries the chronological
 * event slice the caller asked for plus aggregated stats covering the WHOLE
 * session (not just the slice) so streaming clients don't need to refetch
 * to know totals.
 *
 * "Replay" here means reconstruction-grade event stream + summary, not
 * deterministic re-execution against a fresh agent. Re-execution is a
 * separate concern intentionally out of scope.
 */
final readonly class ReplaySummary
{
    /**
     * @param  array<int, array{seq: int, kind: string, payload: array<string, mixed>|null, created_at: string|null}>  $events
     * @param  array<string, int>  $eventsByKind
     */
    public function __construct(
        public string $sessionId,
        public ?AgentSessionStatus $status,
        public ?Carbon $startedAt,
        public ?Carbon $endedAt,
        public ?int $durationSeconds,
        public int $totalEvents,
        public array $eventsByKind,
        public int $llmTotalTokens,
        public float $llmTotalCostUsd,
        public int $toolCallCount,
        public int $toolFailureCount,
        public int $handoffCount,
        public array $events,
        public ?int $nextSinceSeq,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'status' => $this->status?->value,
            'started_at' => $this->startedAt?->toIso8601String(),
            'ended_at' => $this->endedAt?->toIso8601String(),
            'duration_seconds' => $this->durationSeconds,
            'total_events' => $this->totalEvents,
            'events_by_kind' => $this->eventsByKind,
            'llm_total_tokens' => $this->llmTotalTokens,
            'llm_total_cost_usd' => $this->llmTotalCostUsd,
            'tool_call_count' => $this->toolCallCount,
            'tool_failure_count' => $this->toolFailureCount,
            'handoff_count' => $this->handoffCount,
            'events' => $this->events,
            'next_since_seq' => $this->nextSinceSeq,
        ];
    }
}
