<?php

namespace App\Domain\AgentSession\Actions;

use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\AgentSession\Models\AgentSessionEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for the AgentSession event log. Idempotent on
 * (session_id, seq) — duplicate writes silently no-op so retries are
 * safe. Returns the persisted event row.
 */
class AppendSessionEventAction
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function execute(
        AgentSession $session,
        AgentSessionEventKind $kind,
        ?array $payload = null,
        ?int $seq = null,
    ): AgentSessionEvent {
        return DB::transaction(function () use ($session, $kind, $payload, $seq) {
            $resolvedSeq = $seq ?? ($session->lastSeq() + 1);

            try {
                /** @var AgentSessionEvent $event */
                $event = AgentSessionEvent::create([
                    'team_id' => $session->team_id,
                    'session_id' => $session->id,
                    'seq' => $resolvedSeq,
                    'kind' => $kind,
                    'payload' => $payload,
                    'created_at' => now(),
                ]);

                $session->update(['last_heartbeat_at' => now()]);

                return $event;
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    /** @var AgentSessionEvent $existing */
                    $existing = AgentSessionEvent::query()
                        ->where('session_id', $session->id)
                        ->where('seq', $resolvedSeq)
                        ->firstOrFail();

                    return $existing;
                }
                throw $e;
            }
        });
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        return $code === '23000' || $code === '23505';
    }
}
