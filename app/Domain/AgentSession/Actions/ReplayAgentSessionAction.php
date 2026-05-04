<?php

namespace App\Domain\AgentSession\Actions;

use App\Domain\AgentSession\DTOs\ReplaySummary;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\AgentSession\Models\AgentSessionEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Read-only reconstruction of a session's event timeline plus session-wide
 * summary stats. Supports paginated streaming via since_seq + limit so a
 * UI/audit consumer can pull the first 1000 events, then poll for newer
 * ones without refetching.
 *
 * Stats are computed across ALL events (not the returned slice) so the
 * caller gets correct totals on the very first call.
 */
class ReplayAgentSessionAction
{
    public const DEFAULT_LIMIT = 1000;

    public const MAX_LIMIT = 5000;

    /**
     * @param  array<int, AgentSessionEventKind>|null  $kinds  filters the returned events slice; stats still cover all events
     */
    public function execute(
        AgentSession $session,
        int $sinceSeq = 0,
        ?int $limit = null,
        ?array $kinds = null,
    ): ReplaySummary {
        $resolvedLimit = $this->clampLimit($limit);

        $totals = $this->computeSessionTotals($session);

        $sliceQuery = AgentSessionEvent::query()
            ->where('session_id', $session->id)
            ->where('seq', '>', $sinceSeq)
            ->orderBy('seq');
        if ($kinds !== null && $kinds !== []) {
            $sliceQuery->whereIn('kind', array_map(fn (AgentSessionEventKind $k) => $k->value, $kinds));
        }
        /** @var Collection<int, AgentSessionEvent> $sliceEvents */
        $sliceEvents = $sliceQuery->limit($resolvedLimit + 1)->get();

        $hasMore = $sliceEvents->count() > $resolvedLimit;
        if ($hasMore) {
            $sliceEvents = $sliceEvents->slice(0, $resolvedLimit)->values();
        }

        $eventsArr = $sliceEvents->map(fn (AgentSessionEvent $e) => [
            'seq' => $e->seq,
            'kind' => $e->kind->value,
            'payload' => $e->payload,
            'created_at' => $e->created_at->toIso8601String(),
        ])->all();

        $duration = null;
        if ($session->started_at && $session->ended_at) {
            $duration = (int) $session->started_at->diffInSeconds($session->ended_at);
        }

        return new ReplaySummary(
            sessionId: $session->id,
            status: $session->status,
            startedAt: $session->started_at,
            endedAt: $session->ended_at,
            durationSeconds: $duration,
            totalEvents: $totals['total_events'],
            eventsByKind: $totals['events_by_kind'],
            llmTotalTokens: $totals['llm_total_tokens'],
            llmTotalCostUsd: $totals['llm_total_cost_usd'],
            toolCallCount: $totals['tool_call_count'],
            toolFailureCount: $totals['tool_failure_count'],
            handoffCount: $totals['handoff_count'],
            events: $eventsArr,
            nextSinceSeq: $hasMore && $eventsArr !== [] ? (int) end($eventsArr)['seq'] : null,
        );
    }

    /**
     * @return array{
     *   total_events: int,
     *   events_by_kind: array<string, int>,
     *   llm_total_tokens: int,
     *   llm_total_cost_usd: float,
     *   tool_call_count: int,
     *   tool_failure_count: int,
     *   handoff_count: int,
     * }
     */
    private function computeSessionTotals(AgentSession $session): array
    {
        $eventsByKind = [];
        $llmTokens = 0;
        $llmCost = 0.0;
        $toolCalls = 0;
        $toolFailures = 0;
        $handoffs = 0;
        $total = 0;

        AgentSessionEvent::query()
            ->where('session_id', $session->id)
            ->orderBy('seq')
            ->chunk(500, function ($chunk) use (
                &$total, &$eventsByKind, &$llmTokens, &$llmCost,
                &$toolCalls, &$toolFailures, &$handoffs
            ) {
                foreach ($chunk as $event) {
                    /** @var AgentSessionEvent $event */
                    $total++;
                    $kindValue = $event->kind->value;
                    $eventsByKind[$kindValue] = ($eventsByKind[$kindValue] ?? 0) + 1;

                    $payload = is_array($event->payload) ? $event->payload : [];

                    if ($event->kind === AgentSessionEventKind::LlmCall) {
                        $tokens = $payload['tokens_total'] ?? null;
                        if (is_int($tokens) || (is_string($tokens) && ctype_digit($tokens))) {
                            $llmTokens += (int) $tokens;
                        } else {
                            $this->logShapeMiss('LlmCall.tokens_total', $event->id, $tokens);
                        }
                        $cost = $payload['cost_usd'] ?? null;
                        if (is_numeric($cost)) {
                            $llmCost += (float) $cost;
                        } else {
                            $this->logShapeMiss('LlmCall.cost_usd', $event->id, $cost);
                        }
                    }

                    if ($event->kind === AgentSessionEventKind::ToolCall) {
                        $toolCalls++;
                    }

                    if ($event->kind === AgentSessionEventKind::ToolResult) {
                        $errored = $payload['error'] ?? null;
                        if ($errored !== null && $errored !== false && $errored !== '' && $errored !== 0) {
                            $toolFailures++;
                        }
                    }

                    if (
                        $event->kind === AgentSessionEventKind::HandoffOut
                        || $event->kind === AgentSessionEventKind::HandoffIn
                    ) {
                        $handoffs++;
                    }
                }
            });

        return [
            'total_events' => $total,
            'events_by_kind' => $eventsByKind,
            'llm_total_tokens' => $llmTokens,
            'llm_total_cost_usd' => $llmCost,
            'tool_call_count' => $toolCalls,
            'tool_failure_count' => $toolFailures,
            'handoff_count' => $handoffs,
        ];
    }

    private function clampLimit(?int $limit): int
    {
        $value = $limit ?? self::DEFAULT_LIMIT;
        $value = max(1, $value);

        return min($value, self::MAX_LIMIT);
    }

    private function logShapeMiss(string $field, string $eventId, mixed $value): void
    {
        Log::info('AgentSessionReplay: event payload shape mismatch — defaulting to 0', [
            'field' => $field,
            'event_id' => $eventId,
            'received_type' => gettype($value),
        ]);
    }
}
