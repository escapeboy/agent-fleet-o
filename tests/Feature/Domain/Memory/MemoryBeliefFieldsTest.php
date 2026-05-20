<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Enums\MemoryBeliefStatus;
use App\Domain\Memory\Enums\MemoryBeliefType;
use App\Domain\Memory\Enums\MemoryPreferenceSubtype;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Livewire\Memory\MemoryBrowserPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the Tenure-inspired structured belief fields: typed taxonomy,
 * preference subtype, why_it_matters, belief lifecycle status, and domain scope.
 */
class MemoryBeliefFieldsTest extends TestCase
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
            'confidence' => 0.9,
            'importance' => 0.5,
        ], $overrides));
    }

    // ─── Model casts ─────────────────────────────────────────────────────────

    public function test_memory_persists_and_casts_belief_fields(): void
    {
        $memory = $this->createMemory([
            'belief_type' => MemoryBeliefType::Preference,
            'preference_subtype' => MemoryPreferenceSubtype::Style,
            'why_it_matters' => 'Shapes all replies toward terse, code-first answers.',
            'belief_status' => MemoryBeliefStatus::Inferred,
            'domain' => 'domain:code',
        ]);

        $fresh = $memory->fresh();

        $this->assertSame(MemoryBeliefType::Preference, $fresh->belief_type);
        $this->assertSame(MemoryPreferenceSubtype::Style, $fresh->preference_subtype);
        $this->assertSame(MemoryBeliefStatus::Inferred, $fresh->belief_status);
        $this->assertSame('domain:code', $fresh->domain);
        $this->assertSame('Shapes all replies toward terse, code-first answers.', $fresh->why_it_matters);
    }

    public function test_belief_status_defaults_to_active(): void
    {
        $memory = $this->createMemory();

        $this->assertSame(MemoryBeliefStatus::Active, $memory->fresh()->belief_status);
    }

    // ─── Enum helpers ────────────────────────────────────────────────────────

    public function test_only_preference_belief_type_accepts_a_subtype(): void
    {
        $this->assertTrue(MemoryBeliefType::Preference->acceptsPreferenceSubtype());
        $this->assertFalse(MemoryBeliefType::Decision->acceptsPreferenceSubtype());
        $this->assertFalse(MemoryBeliefType::Entity->acceptsPreferenceSubtype());
        $this->assertFalse(MemoryBeliefType::Relation->acceptsPreferenceSubtype());
        $this->assertFalse(MemoryBeliefType::OpenQuestion->acceptsPreferenceSubtype());
    }

    public function test_superseded_belief_status_is_not_injectable(): void
    {
        $this->assertFalse(MemoryBeliefStatus::Superseded->isInjectable());
        $this->assertTrue(MemoryBeliefStatus::Active->isInjectable());
        $this->assertTrue(MemoryBeliefStatus::Inferred->isInjectable());
        $this->assertTrue(MemoryBeliefStatus::Exploratory->isInjectable());
    }

    // ─── MemoryBrowserPage filters ───────────────────────────────────────────

    public function test_browser_page_filters_by_belief_type(): void
    {
        $this->createMemory(['content' => 'A decision', 'belief_type' => MemoryBeliefType::Decision]);
        $this->createMemory(['content' => 'A preference', 'belief_type' => MemoryBeliefType::Preference]);

        Livewire::actingAs($this->ownerUser())
            ->test(MemoryBrowserPage::class)
            ->set('beliefTypeFilter', 'decision')
            ->assertSee('A decision')
            ->assertDontSee('A preference');
    }

    public function test_browser_page_filters_by_belief_status(): void
    {
        $this->createMemory(['content' => 'Active belief', 'belief_status' => MemoryBeliefStatus::Active]);
        $this->createMemory(['content' => 'Superseded belief', 'belief_status' => MemoryBeliefStatus::Superseded]);

        Livewire::actingAs($this->ownerUser())
            ->test(MemoryBrowserPage::class)
            ->set('beliefStatusFilter', 'superseded')
            ->assertSee('Superseded belief')
            ->assertDontSee('Active belief');
    }

    public function test_browser_page_filters_by_domain(): void
    {
        $this->createMemory(['content' => 'Code scoped', 'domain' => 'domain:code']);
        $this->createMemory(['content' => 'Writing scoped', 'domain' => 'domain:writing']);

        Livewire::actingAs($this->ownerUser())
            ->test(MemoryBrowserPage::class)
            ->set('domainFilter', 'domain:code')
            ->assertSee('Code scoped')
            ->assertDontSee('Writing scoped');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function ownerUser(): User
    {
        $user = User::factory()->create();
        $user->teams()->attach($this->team, ['role' => 'owner']);
        $user->current_team_id = $this->team->id;
        $user->save();

        return $user;
    }
}
