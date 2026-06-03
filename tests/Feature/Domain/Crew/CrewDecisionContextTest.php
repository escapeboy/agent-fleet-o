<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Crew\Actions\RecordCrewDecisionAction;
use App\Domain\Crew\Services\CrewDecisionContext;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrewDecisionContextTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $teamId, string $crewId, string $decision, ?string $why = null): void
    {
        app(RecordCrewDecisionAction::class)->execute(
            teamId: $teamId,
            crewId: $crewId,
            decision: $decision,
            whyItMatters: $why,
        );
    }

    public function test_build_returns_null_when_no_decisions(): void
    {
        $team = Team::factory()->create();

        $this->assertNull(app(CrewDecisionContext::class)->build($team->id, Str::uuid7()->toString()));
    }

    public function test_build_renders_decisions_block_with_rationale(): void
    {
        $team = Team::factory()->create();
        $crewId = Str::uuid7()->toString();
        $this->record($team->id, $crewId, 'Use union decomposition for research crews.', 'Higher recall on ambiguous goals.');

        $block = app(CrewDecisionContext::class)->build($team->id, $crewId);

        $this->assertNotNull($block);
        $this->assertStringContainsString('## Team Decisions', $block);
        $this->assertStringContainsString('Use union decomposition for research crews.', $block);
        $this->assertStringContainsString('why: Higher recall on ambiguous goals.', $block);
    }

    public function test_for_is_scoped_to_crew_and_team(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $crewA = Str::uuid7()->toString();
        $crewOther = Str::uuid7()->toString();

        $this->record($teamA->id, $crewA, 'Team A crew A decision.');
        $this->record($teamA->id, $crewOther, 'Team A other crew decision.');
        $this->record($teamB->id, $crewA, 'Team B decision on same crew id.');

        $context = app(CrewDecisionContext::class);

        $rows = $context->for($teamA->id, $crewA);
        $this->assertCount(1, $rows);
        $this->assertSame('Team A crew A decision.', $rows->first()->content);

        // Tenant isolation: team B's decision on the same crew id never leaks.
        $this->assertStringNotContainsString('Team B decision', (string) $context->build($teamA->id, $crewA));
    }

    public function test_for_orders_oldest_first(): void
    {
        $team = Team::factory()->create();
        $crewId = Str::uuid7()->toString();
        $this->record($team->id, $crewId, 'First.');
        $this->record($team->id, $crewId, 'Second.');

        $rows = app(CrewDecisionContext::class)->for($team->id, $crewId);

        $this->assertSame('First.', $rows->first()->content);
        $this->assertSame('Second.', $rows->last()->content);
    }
}
