<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Crew\Actions\RecordCrewDecisionAction;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecordCrewDecisionActionTest extends TestCase
{
    use RefreshDatabase;

    private function team(): Team
    {
        return Team::factory()->create();
    }

    public function test_records_decision_with_ledger_markers(): void
    {
        $team = $this->team();
        $crewId = Str::uuid7()->toString();

        $memory = app(RecordCrewDecisionAction::class)->execute(
            teamId: $team->id,
            crewId: $crewId,
            decision: 'Security guards are hooks, not prompts.',
            whyItMatters: 'Prompts can be ignored; hooks execute deterministically.',
        );

        $this->assertSame('Security guards are hooks, not prompts.', $memory->content);
        $this->assertSame(RecordCrewDecisionAction::SOURCE_TYPE, $memory->source_type);
        $this->assertSame($crewId, $memory->source_id);
        $this->assertSame(MemoryTier::Decisions, $memory->tier);
        $this->assertSame(MemoryCategory::Facts, $memory->category);
        $this->assertTrue($memory->metadata['decision']);
        $this->assertSame($crewId, $memory->metadata['crew_id']);
        $this->assertSame('Prompts can be ignored; hooks execute deterministically.', $memory->why_it_matters);
        $this->assertNull($memory->agent_id);
    }

    public function test_each_record_is_append_only(): void
    {
        $team = $this->team();
        $crewId = Str::uuid7()->toString();
        $action = app(RecordCrewDecisionAction::class);

        $action->execute(teamId: $team->id, crewId: $crewId, decision: 'First decision.');
        $action->execute(teamId: $team->id, crewId: $crewId, decision: 'Second decision.');

        $this->assertSame(2, Memory::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('source_type', RecordCrewDecisionAction::SOURCE_TYPE)
            ->where('source_id', $crewId)
            ->count());
    }
}
