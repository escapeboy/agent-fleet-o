<?php

namespace Tests\Feature\Domain\ProductGraph;

use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use App\Domain\ProductGraph\Models\ProductNode;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\ProductGraph\ProductGraphImpactTool;
use App\Mcp\Tools\ProductGraph\ProductGraphProposeTool;
use App\Mcp\Tools\ProductGraph\ProductGraphQueryTool;
use App\Mcp\Tools\ProductGraph\ProductGraphReviewTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class ProductGraphMcpTest extends TestCase
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
        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_query_returns_only_caller_team_nodes(): void
    {
        ProductNode::factory()->create(['team_id' => $this->team->id, 'name' => 'Mine']);

        $otherTeam = Team::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->team->owner_id, 'settings' => []]);
        ProductNode::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Theirs']);

        $response = app(ProductGraphQueryTool::class)->handle(new Request([]));
        $data = json_decode((string) $response->content(), true);

        $this->assertSame(1, $data['count']);
        $this->assertSame('Mine', $data['nodes'][0]['name']);
    }

    public function test_impact_tool_returns_blast_radius(): void
    {
        $a = ProductNode::factory()->create(['team_id' => $this->team->id, 'name' => 'A']);
        $b = ProductNode::factory()->create(['team_id' => $this->team->id, 'name' => 'B']);
        ProductEdge::factory()->edgeType(EdgeType::DependsOn)->create([
            'team_id' => $this->team->id,
            'source_node_id' => $a->id,
            'target_node_id' => $b->id,
        ]);

        $response = app(ProductGraphImpactTool::class)->handle(new Request(['node_id' => $b->id]));
        $data = json_decode((string) $response->content(), true);

        $this->assertSame(1, $data['affected_count']);
        $this->assertSame('A', $data['affected'][0]['name']);
    }

    public function test_propose_tool_queues_without_mutating_graph(): void
    {
        $response = app(ProductGraphProposeTool::class)->handle(new Request([
            'change_type' => 'create_node',
            'payload' => ['node_type' => 'feature', 'name' => 'Proposed'],
            'proposed_by_label' => 'agent:test',
        ]));
        $data = json_decode((string) $response->content(), true);

        $this->assertSame('pending', $data['status']);
        $this->assertSame(0, ProductNode::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
        $this->assertSame(1, ProductGraphChange::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_review_tool_approve_applies_change(): void
    {
        $change = ProductGraphChange::factory()->create([
            'team_id' => $this->team->id,
            'payload' => ['node_type' => 'feature', 'name' => 'Approved Via Mcp'],
        ]);

        $response = app(ProductGraphReviewTool::class)->handle(new Request([
            'change_id' => $change->id,
            'decision' => 'approve',
        ]));
        $data = json_decode((string) $response->content(), true);

        $this->assertSame('applied', $data['status']);
        $this->assertSame(1, ProductNode::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_tool_without_team_context_is_permission_denied(): void
    {
        app()->instance('mcp.team_id', null);

        $response = app(ProductGraphQueryTool::class)->handle(new Request([]));

        $this->assertTrue($response->isError());
    }
}
