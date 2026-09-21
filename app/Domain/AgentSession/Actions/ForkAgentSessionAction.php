<?php

namespace App\Domain\AgentSession\Actions;

use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\AgentSession\Models\AgentSessionEvent;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Branch a session at a chosen point in its event log. The child is an
 * independent session under the same agent and team, holding a copy of the
 * source's events through `atSeq`. The source is NOT paused, NOT re-statused
 * and keeps appending — that is what separates a fork from a handoff.
 *
 * Events are copied, not referenced. CleanupAgentSessionEvents prunes the
 * parent's events at 90 days and then deletes the orphaned terminal parent
 * row, so a pointer-based child would silently lose its history. The FK is
 * nullOnDelete for the same reason: losing the parent must cost only the
 * back-reference.
 *
 * Borrowed concept: Cherry Studio agent session fork (AGPL-3.0, idea only).
 */
class ForkAgentSessionAction
{
    /**
     * Upper bound on the slice copied into a fork. Matches
     * ReplayAgentSessionAction::MAX_LIMIT so the two agree on what counts as an
     * unreasonably long session.
     */
    public const MAX_FORK_EVENTS = 5000;

    private const COPY_CHUNK = 500;

    public function __construct(
        private readonly AppendSessionEventAction $append,
    ) {}

    /**
     * @param  int|null  $atSeq  Source seq to branch at; defaults to the last event
     * @return array{source: AgentSession, child: AgentSession, forked_at_seq: int, copied_events: int}
     */
    public function execute(
        AgentSession $source,
        ?int $atSeq = null,
        ?string $note = null,
    ): array {
        $lastSeq = $source->lastSeq();
        if ($lastSeq < 1) {
            throw new RuntimeException('Cannot fork a session with no events.');
        }

        $resolvedSeq = $atSeq ?? $lastSeq;
        if ($resolvedSeq < 1) {
            throw new InvalidArgumentException('at_seq must be >= 1.');
        }
        if ($resolvedSeq > $lastSeq) {
            throw new InvalidArgumentException("at_seq {$resolvedSeq} is beyond the session's last event ({$lastSeq}).");
        }

        $sliceCount = $this->sliceQuery($source, $resolvedSeq)->count();
        if ($sliceCount > self::MAX_FORK_EVENTS) {
            throw new RuntimeException(
                "Refusing to fork {$sliceCount} events — the limit is ".self::MAX_FORK_EVENTS.'. Fork at an earlier seq.',
            );
        }

        return DB::transaction(function () use ($source, $resolvedSeq, $note): array {
            /** @var AgentSession $child */
            $child = AgentSession::create([
                'team_id' => $source->team_id,
                'agent_id' => $source->agent_id,
                'experiment_id' => $source->experiment_id,
                'crew_execution_id' => $source->crew_execution_id,
                'parent_session_id' => $source->id,
                'forked_at_seq' => $resolvedSeq,
                'user_id' => $source->user_id,
                'status' => AgentSessionStatus::Pending,
                'workspace_contract_snapshot' => $source->workspace_contract_snapshot,
                'metadata' => [
                    'fork' => [
                        'from_session_id' => $source->id,
                        'at_seq' => $resolvedSeq,
                        'note' => $note,
                        'created_at' => Carbon::now()->toIso8601String(),
                    ],
                ],
            ]);

            $copied = $this->copyEvents($source, $child, $resolvedSeq);

            // Appended last, so the source's own fork marker never lands inside
            // the copied slice — the child's history ends at the forked turn.
            $this->append->execute(
                session: $source,
                kind: AgentSessionEventKind::Fork,
                payload: [
                    'child_session_id' => $child->id,
                    'forked_at_seq' => $resolvedSeq,
                    'copied_events' => $copied,
                    'note' => $note,
                ],
            );

            return [
                'source' => $source->refresh(),
                'child' => $child->refresh(),
                'forked_at_seq' => $resolvedSeq,
                'copied_events' => $copied,
            ];
        });
    }

    /**
     * Copy the slice verbatim. Deliberately bypasses AppendSessionEventAction:
     * that action resolves seq as lastSeq()+1, which would renumber the slice
     * and destroy the seq correspondence the fork contract depends on.
     *
     * Reads and writes go through the query builder rather than Eloquent so the
     * jsonb payload is carried across as stored, with no decode/encode round
     * trip. team_id is set explicitly on every row because a raw insert fires
     * no model events and so never reaches TeamScope.
     *
     * seq, kind and payload are preserved verbatim; created_at is NOT — see the
     * retention note inside.
     */
    private function copyEvents(AgentSession $source, AgentSession $child, int $atSeq): int
    {
        $prototype = new AgentSessionEvent;
        $copied = 0;
        // Retention is keyed on created_at: CleanupAgentSessionEvents deletes
        // every row older than 90 days, globally. Copying the parent's
        // timestamps would hand the child an already-expired history and the
        // next nightly run would empty it — the exact loss this action copies
        // rows to avoid. The copies are written now, so they are stamped now.
        // Ordering is unaffected; every read path orders by seq, not created_at.
        $stampedAt = now();

        $this->sliceQuery($source, $atSeq)
            ->orderBy('seq')
            ->chunk(self::COPY_CHUNK, function ($rows) use ($child, $source, $prototype, $stampedAt, &$copied): void {
                $insert = [];
                foreach ($rows as $row) {
                    $insert[] = [
                        'id' => $prototype->newUniqueId(),
                        'team_id' => $source->team_id,
                        'session_id' => $child->id,
                        'seq' => $row->seq,
                        'kind' => $row->kind,
                        'payload' => $row->payload,
                        'created_at' => $stampedAt,
                    ];
                }

                DB::table('agent_session_events')->insert($insert);
                $copied += count($insert);
            });

        return $copied;
    }

    /**
     * @return Builder
     */
    private function sliceQuery(AgentSession $source, int $atSeq)
    {
        return DB::table('agent_session_events')
            ->where('team_id', $source->team_id)
            ->where('session_id', $source->id)
            ->where('seq', '<=', $atSeq);
    }
}
