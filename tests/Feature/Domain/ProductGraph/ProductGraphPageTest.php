<?php

namespace Tests\Feature\Domain\ProductGraph;

use App\Domain\ProductGraph\Enums\ChangeStatus;
use App\Domain\ProductGraph\Enums\ChangeType;
use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use App\Domain\ProductGraph\Models\ProductNode;
use App\Domain\Shared\Models\Team;
use App\Livewire\ProductGraph\ProductGraphBrowserPage;
use App\Livewire\ProductGraph\ProductGraphChangesPage;
use App\Livewire\ProductGraph\ProductGraphImpactPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductGraphPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->actingAs($this->user);
    }

    public function test_browser_shows_disabled_notice_when_flag_off(): void
    {
        config(['productgraph.enabled' => false]);

        Livewire::test(ProductGraphBrowserPage::class)
            ->assertSee('Product Graph is disabled');
    }

    public function test_owner_can_add_a_node(): void
    {
        config(['productgraph.enabled' => true]);

        Livewire::test(ProductGraphBrowserPage::class)
            ->set('newName', 'New Feature')
            ->set('newNodeType', 'feature')
            ->set('newStatus', 'planned')
            ->call('addNode')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_nodes', [
            'team_id' => $this->team->id,
            'name' => 'New Feature',
        ]);
    }

    public function test_changes_page_approve_applies_proposal(): void
    {
        config(['productgraph.enabled' => true]);

        $change = ProductGraphChange::factory()->create([
            'team_id' => $this->team->id,
            'change_type' => ChangeType::CreateNode,
            'payload' => ['node_type' => 'feature', 'name' => 'From Queue'],
            'status' => ChangeStatus::Pending,
        ]);

        Livewire::test(ProductGraphChangesPage::class)
            ->call('approve', $change->id);

        $this->assertDatabaseHas('product_nodes', [
            'team_id' => $this->team->id,
            'name' => 'From Queue',
        ]);
    }

    public function test_impact_page_renders_blast_radius(): void
    {
        config(['productgraph.enabled' => true]);

        $a = ProductNode::factory()->create(['team_id' => $this->team->id, 'name' => 'Dependent A']);
        $b = ProductNode::factory()->create(['team_id' => $this->team->id, 'name' => 'Core B']);
        ProductEdge::factory()->edgeType(EdgeType::DependsOn)->create([
            'team_id' => $this->team->id,
            'source_node_id' => $a->id,
            'target_node_id' => $b->id,
        ]);

        Livewire::test(ProductGraphImpactPage::class)
            ->set('nodeId', $b->id)
            ->assertSee('Dependent A');
    }
}
