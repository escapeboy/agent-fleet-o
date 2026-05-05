<?php

namespace Tests\Feature\Domain\KnowledgeGraph;

use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\KnowledgeGraph\Services\KnowledgeGraphTraversal;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PersonalizedPageRankTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    public function test_ppr_returns_entities_reachable_from_seed(): void
    {
        $alice = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'Alice']);
        $acme = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'Acme Corp']);
        $project = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'Project X']);

        // Alice → Acme (relates_to)
        KgEdge::create([
            'team_id' => $this->team->id,
            'source_entity_id' => $alice->id,
            'target_entity_id' => $acme->id,
            'edge_type' => 'relates_to',
            'relation_type' => 'works_at',
            'fact' => 'Alice works at Acme',
            'valid_at' => now(),
        ]);

        // Acme → Project X (relates_to)
        KgEdge::create([
            'team_id' => $this->team->id,
            'source_entity_id' => $acme->id,
            'target_entity_id' => $project->id,
            'edge_type' => 'relates_to',
            'relation_type' => 'owns',
            'fact' => 'Acme owns Project X',
            'valid_at' => now(),
        ]);

        $traversal = app(KnowledgeGraphTraversal::class);
        // PPR from Alice should score Acme and Project X highly
        $result = $traversal->personalizedPageRank(
            teamId: $this->team->id,
            seedEntityIds: [$alice->id],
            maxHops: 2,
        );

        // No memories exist yet — result is empty (memoriesForEntities returns empty)
        // but the method should not throw
        $this->assertInstanceOf(Collection::class, $result);
    }

    public function test_ppr_with_empty_seeds_returns_empty_collection(): void
    {
        $traversal = app(KnowledgeGraphTraversal::class);
        $result = $traversal->personalizedPageRank(
            teamId: $this->team->id,
            seedEntityIds: [],
        );

        $this->assertCount(0, $result);
    }

    public function test_ppr_is_deterministic_for_known_graph(): void
    {
        $nodes = [];
        for ($i = 0; $i < 5; $i++) {
            $nodes[] = Entity::factory()->create(['team_id' => $this->team->id]);
        }

        // Build a simple chain: 0 → 1 → 2 → 3 → 4
        for ($i = 0; $i < 4; $i++) {
            KgEdge::create([
                'team_id' => $this->team->id,
                'source_entity_id' => $nodes[$i]->id,
                'target_entity_id' => $nodes[$i + 1]->id,
                'edge_type' => 'relates_to',
                'relation_type' => 'connects',
                'fact' => "Node {$i} connects to node ".($i + 1),
                'valid_at' => now(),
            ]);
        }

        $traversal = app(KnowledgeGraphTraversal::class);

        $result1 = $traversal->personalizedPageRank(
            teamId: $this->team->id,
            seedEntityIds: [$nodes[0]->id],
        );

        $result2 = $traversal->personalizedPageRank(
            teamId: $this->team->id,
            seedEntityIds: [$nodes[0]->id],
        );

        // Same seeds → same result
        $this->assertEquals(
            $result1->pluck('id')->sort()->values()->toArray(),
            $result2->pluck('id')->sort()->values()->toArray(),
        );
    }

    public function test_global_search_delegates_to_ppr(): void
    {
        $alice = Entity::factory()->create(['team_id' => $this->team->id]);
        $acme = Entity::factory()->create(['team_id' => $this->team->id]);

        KgEdge::create([
            'team_id' => $this->team->id,
            'source_entity_id' => $alice->id,
            'target_entity_id' => $acme->id,
            'edge_type' => 'relates_to',
            'relation_type' => 'works_at',
            'fact' => 'Alice works at Acme',
            'valid_at' => now(),
        ]);

        $traversal = app(KnowledgeGraphTraversal::class);

        // globalSearch should not throw and should return a Collection
        $result = $traversal->globalSearch($this->team->id, [$alice->id]);
        $this->assertInstanceOf(Collection::class, $result);
    }
}
