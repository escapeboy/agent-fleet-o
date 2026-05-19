<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Enums\MemoryBeliefStatus;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Livewire\Memory\MemoryBrowserPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the RoBrain-borrowed memory fields: structured rejected_alternatives,
 * supersession lineage, and contradiction-flag review surfaces.
 */
class MemoryRobrainBorrowedTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     */
    private function createMemory(array $overrides = []): Memory
    {
        return Memory::create(array_merge([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'Test memory content',
            'source_type' => 'test',
        ], $overrides));
    }

    private function ownerUser(): User
    {
        $user = User::factory()->create();
        $user->teams()->attach($this->team, ['role' => 'owner']);
        $user->current_team_id = $this->team->id;
        $user->save();

        return $user;
    }

    // ─── Model casts & relations ─────────────────────────────────────────────

    public function test_memory_casts_rejected_alternatives_and_conflict_flag(): void
    {
        $memory = $this->createMemory([
            'rejected_alternatives' => [['option' => 'Redux', 'reason' => 're-render perf']],
            'conflict_flag' => true,
        ]);

        $fresh = $memory->fresh();

        $this->assertIsArray($fresh->rejected_alternatives);
        $this->assertSame('Redux', $fresh->rejected_alternatives[0]['option']);
        $this->assertTrue($fresh->conflict_flag);
    }

    public function test_supersedes_relation_links_the_decision_lineage(): void
    {
        $old = $this->createMemory(['content' => 'Old decision']);
        $new = $this->createMemory(['content' => 'New decision', 'supersedes_id' => $old->id]);

        $this->assertSame($old->id, $new->supersedes->id);
        $this->assertTrue($old->supersededBy->contains($new->id));
    }

    // ─── StoreMemoryAction normalisation ─────────────────────────────────────

    public function test_store_memory_action_normalizes_rejected_alternatives(): void
    {
        $method = new \ReflectionMethod(StoreMemoryAction::class, 'normalizeRejectedAlternatives');

        $clean = $method->invoke(new StoreMemoryAction, [
            ['option' => 'Redux', 'reason' => 're-render perf'],
            ['reason' => 'no option key — dropped'],
            ['option' => '   '],
            'not-an-array',
            ['option' => 'Vuex'],
        ]);

        $this->assertCount(2, $clean);
        $this->assertSame('Redux', $clean[0]['option']);
        $this->assertSame('re-render perf', $clean[0]['reason']);
        $this->assertSame('Vuex', $clean[1]['option']);
        $this->assertSame('', $clean[1]['reason']);
    }

    // ─── MemoryBrowserPage — contradiction review ────────────────────────────

    public function test_browser_page_conflict_filter_shows_only_flagged_memories(): void
    {
        $this->createMemory(['content' => 'Flagged belief', 'conflict_flag' => true]);
        $this->createMemory(['content' => 'Calm belief', 'conflict_flag' => false]);

        Livewire::actingAs($this->ownerUser())
            ->test(MemoryBrowserPage::class)
            ->set('conflictFilter', '1')
            ->assertSee('Flagged belief')
            ->assertDontSee('Calm belief');
    }

    public function test_browser_page_resolve_conflict_supersedes_the_partner(): void
    {
        $keep = $this->createMemory(['content' => 'Keep this belief', 'conflict_flag' => true]);
        $loser = $this->createMemory(['content' => 'Losing belief', 'conflict_flag' => true]);
        $keep->update(['conflict_with_id' => $loser->id]);
        $loser->update(['conflict_with_id' => $keep->id]);

        Livewire::actingAs($this->ownerUser())
            ->test(MemoryBrowserPage::class)
            ->call('resolveConflict', $keep->id, 'supersede');

        $this->assertFalse($keep->fresh()->conflict_flag);
        $this->assertSame($loser->id, $keep->fresh()->supersedes_id);
        $this->assertSame(MemoryBeliefStatus::Superseded, $loser->fresh()->belief_status);
    }

    public function test_browser_page_expanded_row_shows_rejected_alternatives(): void
    {
        $memory = $this->createMemory([
            'content' => 'Chose Zustand for cart state',
            'rejected_alternatives' => [['option' => 'Redux', 'reason' => 're-render perf issues']],
        ]);

        Livewire::actingAs($this->ownerUser())
            ->test(MemoryBrowserPage::class)
            ->call('toggleExpand', $memory->id)
            ->assertSee('Ruled-out alternatives')
            ->assertSee('Redux')
            ->assertSee('re-render perf issues');
    }
}
