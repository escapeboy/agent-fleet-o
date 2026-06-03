<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Memory\Enums\MemoryBeliefStatus;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Memory\Models\Memory;

/**
 * Record a durable crew decision (Squad borrow: "decisions are the shared brain").
 *
 * A decision is a Memory with tier=decisions, category=facts, and the plain-column
 * markers source_type='crew_decision' + source_id={crew_id} so it can be listed and
 * injected reliably across editions (SQLite tests + Postgres) without JSONB operators.
 * Append-only, human-readable, inherited by future agents as a constraint.
 *
 * No embedding is stored — the ledger is queried by crew + marker, not by vector,
 * and the `embedding` pgvector column is written only via raw SQL elsewhere.
 */
class RecordCrewDecisionAction
{
    public const SOURCE_TYPE = 'crew_decision';

    public function execute(
        string $teamId,
        string $crewId,
        string $decision,
        ?string $whyItMatters = null,
        ?string $projectId = null,
        ?string $decidedBy = null,
    ): Memory {
        $content = trim($decision);

        return Memory::create([
            'team_id' => $teamId,
            'agent_id' => null,
            'project_id' => $projectId,
            'content' => $content,
            'content_hash' => hash('sha256', mb_strtolower($content)),
            'metadata' => array_filter([
                'decision' => true,
                'crew_id' => $crewId,
                'decided_by' => $decidedBy,
            ], fn ($v) => $v !== null),
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $crewId,
            'confidence' => 1.0,
            'importance' => 0.9,
            'tags' => ['decision'],
            'visibility' => MemoryVisibility::Team,
            'tier' => MemoryTier::Decisions,
            'category' => MemoryCategory::Facts,
            'why_it_matters' => $whyItMatters,
            'belief_status' => MemoryBeliefStatus::Active,
        ]);
    }
}
