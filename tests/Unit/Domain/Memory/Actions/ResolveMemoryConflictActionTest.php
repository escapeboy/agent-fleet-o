<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Actions\ResolveMemoryConflictAction;
use App\Domain\Memory\Enums\MemoryBeliefStatus;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveMemoryConflictActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
    }

    /**
     * @return array{0: Memory, 1: Memory}
     */
    private function flaggedPair(): array
    {
        $a = Memory::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'Use Bun as the JS runtime',
            'source_type' => 'test',
            'conflict_flag' => true,
        ]);
        $b = Memory::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'Migrated back to Node — Bun was missing packages',
            'source_type' => 'test',
            'conflict_flag' => true,
        ]);
        $a->update(['conflict_with_id' => $b->id]);
        $b->update(['conflict_with_id' => $a->id]);

        return [$a->fresh(), $b->fresh()];
    }

    public function test_supersede_marks_partner_superseded_and_links_lineage(): void
    {
        [$keep, $loser] = $this->flaggedPair();

        $result = (new ResolveMemoryConflictAction)->execute(
            memoryId: $keep->id,
            teamId: $this->team->id,
            resolution: ResolveMemoryConflictAction::RESOLUTION_SUPERSEDE,
        );

        $this->assertFalse($result->conflict_flag);
        $this->assertSame($loser->id, $result->supersedes_id);
        $this->assertSame(MemoryBeliefStatus::Superseded, $loser->fresh()->belief_status);
        $this->assertFalse($loser->fresh()->conflict_flag);
    }

    public function test_dismiss_clears_flags_without_superseding(): void
    {
        [$keep, $partner] = $this->flaggedPair();

        (new ResolveMemoryConflictAction)->execute(
            memoryId: $keep->id,
            teamId: $this->team->id,
            resolution: ResolveMemoryConflictAction::RESOLUTION_DISMISS,
        );

        $this->assertFalse($keep->fresh()->conflict_flag);
        $this->assertNull($keep->fresh()->supersedes_id);
        $this->assertFalse($partner->fresh()->conflict_flag);
        $this->assertSame(MemoryBeliefStatus::Active, $partner->fresh()->belief_status);
    }

    public function test_throws_when_memory_is_not_flagged(): void
    {
        $memory = Memory::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'A calm, unconflicted belief',
            'source_type' => 'test',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        (new ResolveMemoryConflictAction)->execute(
            memoryId: $memory->id,
            teamId: $this->team->id,
            resolution: ResolveMemoryConflictAction::RESOLUTION_DISMISS,
        );
    }

    public function test_throws_on_unknown_resolution(): void
    {
        [$keep] = $this->flaggedPair();

        $this->expectException(\InvalidArgumentException::class);

        (new ResolveMemoryConflictAction)->execute(
            memoryId: $keep->id,
            teamId: $this->team->id,
            resolution: 'merge',
        );
    }

    public function test_throws_when_memory_not_found(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ResolveMemoryConflictAction)->execute(
            memoryId: '00000000-0000-0000-0000-000000000000',
            teamId: $this->team->id,
            resolution: ResolveMemoryConflictAction::RESOLUTION_DISMISS,
        );
    }
}
