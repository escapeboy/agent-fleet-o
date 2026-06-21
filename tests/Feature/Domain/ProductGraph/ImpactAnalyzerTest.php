<?php

namespace Tests\Feature\Domain\ProductGraph;

use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Enums\NodeType;
use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductNode;
use App\Domain\ProductGraph\Services\ImpactAnalyzer;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpactAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ImpactAnalyzer $analyzer;

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
        $this->analyzer = app(ImpactAnalyzer::class);
    }

    private function node(string $name, NodeType $type = NodeType::Feature): ProductNode
    {
        return ProductNode::factory()->type($type)->create([
            'team_id' => $this->team->id,
            'name' => $name,
        ]);
    }

    private function edge(ProductNode $source, ProductNode $target, EdgeType $type): void
    {
        ProductEdge::factory()->edgeType($type)->create([
            'team_id' => $this->team->id,
            'source_node_id' => $source->id,
            'target_node_id' => $target->id,
        ]);
    }

    public function test_impact_direction_per_edge_type(): void
    {
        $this->assertSame('incoming', EdgeType::DependsOn->impactDirection());
        $this->assertSame('incoming', EdgeType::Uses->impactDirection());
        $this->assertSame('incoming', EdgeType::PartOf->impactDirection());
        $this->assertSame('outgoing', EdgeType::Serves->impactDirection());
        $this->assertSame('outgoing', EdgeType::Triggers->impactDirection());
        $this->assertSame('both', EdgeType::IntegratesWith->impactDirection());
    }

    public function test_depends_on_chain_propagates_upstream(): void
    {
        $a = $this->node('A');
        $b = $this->node('B');
        $c = $this->node('C');
        $this->edge($a, $b, EdgeType::DependsOn); // A depends_on B
        $this->edge($b, $c, EdgeType::DependsOn); // B depends_on C

        $impact = collect($this->analyzer->blastRadius($c));

        $this->assertEqualsCanonicalizing(['B', 'A'], $impact->pluck('name')->all());
        $this->assertSame(1, $impact->firstWhere('name', 'B')['depth']);
        $this->assertSame(2, $impact->firstWhere('name', 'A')['depth']);
    }

    public function test_integrates_with_cycle_terminates(): void
    {
        $a = $this->node('A');
        $b = $this->node('B');
        $this->edge($a, $b, EdgeType::IntegratesWith);

        $impact = collect($this->analyzer->blastRadius($a));

        $this->assertSame(['B'], $impact->pluck('name')->all());
    }

    public function test_max_depth_is_respected(): void
    {
        $a = $this->node('A');
        $b = $this->node('B');
        $c = $this->node('C');
        $this->edge($a, $b, EdgeType::DependsOn);
        $this->edge($b, $c, EdgeType::DependsOn);

        $impact = collect($this->analyzer->blastRadius($c, maxDepth: 1));

        $this->assertSame(['B'], $impact->pluck('name')->all());
    }

    public function test_serves_propagates_downstream(): void
    {
        $scoreReport = $this->node('Score Report', NodeType::SharedComponent);
        $p1 = $this->node('Product One', NodeType::Product);
        $p2 = $this->node('Product Two', NodeType::Product);
        $this->edge($scoreReport, $p1, EdgeType::Serves);
        $this->edge($scoreReport, $p2, EdgeType::Serves);

        $impact = collect($this->analyzer->blastRadius($scoreReport));

        $this->assertEqualsCanonicalizing(['Product One', 'Product Two'], $impact->pluck('name')->all());
    }
}
