<?php

namespace Tests\Feature\Domain\ProductGraph;

use App\Domain\ProductGraph\Actions\ApplyApprovedChangeAction;
use App\Domain\ProductGraph\Actions\CreateNodeAction;
use App\Domain\ProductGraph\Actions\ImportFromInventoryAction;
use App\Domain\ProductGraph\Actions\ProposeChangeAction;
use App\Domain\ProductGraph\Actions\ReviewChangeAction;
use App\Domain\ProductGraph\Actions\UpsertEdgeAction;
use App\Domain\ProductGraph\Enums\ChangeStatus;
use App\Domain\ProductGraph\Enums\ChangeType;
use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Enums\NodeType;
use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use App\Domain\ProductGraph\Models\ProductNode;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProductGraphActionsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    public function test_create_node_is_idempotent_on_type_and_slug(): void
    {
        $action = app(CreateNodeAction::class);
        $action->execute($this->team->id, NodeType::Feature, 'Score Report');
        $action->execute($this->team->id, NodeType::Feature, 'Score Report');

        $this->assertSame(1, ProductNode::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_upsert_edge_is_idempotent(): void
    {
        [$a, $b] = $this->twoNodes();
        $action = app(UpsertEdgeAction::class);
        $action->execute($this->team->id, $a->id, $b->id, EdgeType::DependsOn);
        $action->execute($this->team->id, $a->id, $b->id, EdgeType::DependsOn);

        $this->assertSame(1, ProductEdge::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_upsert_edge_rejects_self_loop(): void
    {
        [$a] = $this->twoNodes();
        $this->expectException(InvalidArgumentException::class);
        app(UpsertEdgeAction::class)->execute($this->team->id, $a->id, $a->id, EdgeType::DependsOn);
    }

    public function test_upsert_edge_rejects_cross_team_node(): void
    {
        [$a] = $this->twoNodes();
        $otherTeam = Team::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->team->owner_id, 'settings' => []]);
        $foreign = ProductNode::factory()->create(['team_id' => $otherTeam->id]);

        $this->expectException(InvalidArgumentException::class);
        app(UpsertEdgeAction::class)->execute($this->team->id, $a->id, $foreign->id, EdgeType::DependsOn);
    }

    public function test_propose_change_does_not_mutate_graph(): void
    {
        app(ProposeChangeAction::class)->execute(
            $this->team->id,
            ChangeType::CreateNode,
            null,
            ['node_type' => 'feature', 'name' => 'Proposed Feature'],
            'agent:test',
        );

        $this->assertSame(1, ProductGraphChange::withoutGlobalScopes()->where('team_id', $this->team->id)->pending()->count());
        $this->assertSame(0, ProductNode::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_approving_create_node_proposal_applies_it(): void
    {
        $change = app(ProposeChangeAction::class)->execute(
            $this->team->id,
            ChangeType::CreateNode,
            null,
            ['node_type' => 'feature', 'name' => 'Approved Feature'],
            'agent:test',
        );

        $reviewed = app(ReviewChangeAction::class)->execute($change, approve: true);

        $this->assertSame(ChangeStatus::Applied, $reviewed->status);
        $this->assertNotNull($reviewed->applied_ref_id);
        $this->assertSame(1, ProductNode::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_rejecting_proposal_does_not_mutate_graph(): void
    {
        $change = app(ProposeChangeAction::class)->execute(
            $this->team->id,
            ChangeType::CreateNode,
            null,
            ['node_type' => 'feature', 'name' => 'Rejected Feature'],
        );

        $reviewed = app(ReviewChangeAction::class)->execute($change, approve: false, note: 'no');

        $this->assertSame(ChangeStatus::Rejected, $reviewed->status);
        $this->assertSame(0, ProductNode::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_apply_approved_change_is_idempotent(): void
    {
        $change = ProductGraphChange::factory()->create([
            'team_id' => $this->team->id,
            'change_type' => ChangeType::CreateNode,
            'payload' => ['node_type' => 'feature', 'name' => 'Once Only'],
            'status' => ChangeStatus::Approved,
        ]);

        $apply = app(ApplyApprovedChangeAction::class);
        $apply->execute($change);
        $apply->execute($change->refresh());

        $this->assertSame(1, ProductNode::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_propose_change_with_invalid_payload_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ProposeChangeAction::class)->execute(
            $this->team->id,
            ChangeType::CreateNode,
            null,
            ['node_type' => 'feature'], // missing name
        );
    }

    public function test_import_from_inventory_seeds_graph_idempotently(): void
    {
        $markdown = <<<'MD'
        # Inventory

        Full base domain list: Agent, Crew, Experiment, Signal, Workflow

        ## ⭐ BACKLOG: Capabilities that need a UI

        | # | Capability | Domain | exists | missing | Priority |
        |---|---|---|---|---|---|
        | 1 | **Release signing-key management** (key mgmt) | Release | code | UI | High |
        | 2 | **Drift dashboard** | Evaluation | code | UI | Medium |

        ## Next section
        MD;

        $action = app(ImportFromInventoryAction::class);
        $first = $action->execute($this->team->id, $markdown);

        // 1 root product + 5 domains + 2 backlog = 8 nodes; 7 part_of edges.
        $this->assertSame(8, $first['nodes_created']);
        $this->assertSame(7, $first['edges_created']);
        $this->assertSame(8, ProductNode::withoutGlobalScopes()->where('team_id', $this->team->id)->count());

        // Re-run is idempotent: no new rows.
        $second = $action->execute($this->team->id, $markdown);
        $this->assertSame(0, $second['nodes_created']);
        $this->assertSame(8, ProductNode::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    /**
     * @return array{0: ProductNode, 1: ProductNode}
     */
    private function twoNodes(): array
    {
        return [
            ProductNode::factory()->create(['team_id' => $this->team->id, 'name' => 'Node A']),
            ProductNode::factory()->create(['team_id' => $this->team->id, 'name' => 'Node B']),
        ];
    }
}
