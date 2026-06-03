<?php

namespace App\Domain\Crew\Services;

use App\Domain\Crew\Actions\RecordCrewDecisionAction;
use App\Domain\Memory\Enums\MemoryBeliefStatus;
use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Collection;

/**
 * Reads back the crew decision ledger (Squad borrow). Used both to inject
 * "## Team Decisions" into the coordinator's decomposition prompt and to back
 * the crew_decision_list MCP tool — one query path, no duplication.
 */
class CrewDecisionContext
{
    /**
     * Active decisions for a crew, newest last (append-only reading order).
     *
     * @return Collection<int, Memory>
     */
    public function for(string $teamId, string $crewId, ?int $limit = null): Collection
    {
        $limit ??= (int) config('crew.decision_log.max_injected', 20);

        return Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('source_type', RecordCrewDecisionAction::SOURCE_TYPE)
            ->where('source_id', $crewId)
            ->where('belief_status', MemoryBeliefStatus::Active->value)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Render the decision ledger as a prompt block, or null when empty.
     * Decisions are framed as inherited constraints (Squad: "constraints
     * future agents inherit", not documentation archaeology).
     */
    public function build(string $teamId, string $crewId, ?int $limit = null): ?string
    {
        $decisions = $this->for($teamId, $crewId, $limit);

        if ($decisions->isEmpty()) {
            return null;
        }

        $lines = $decisions->map(function (Memory $m) {
            $line = "- {$m->content}";
            if (! empty($m->why_it_matters)) {
                $line .= " (why: {$m->why_it_matters})";
            }

            return $line;
        })->implode("\n");

        return "## Team Decisions (constraints you inherit — do not contradict)\n{$lines}";
    }
}
