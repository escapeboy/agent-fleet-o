<?php

namespace Tests\Feature\Domain\KnowledgeGraph;

use App\Domain\KnowledgeGraph\Actions\DetectDuplicateEntitiesAction;
use App\Domain\KnowledgeGraph\Actions\MergeEntitiesAction;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Entity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityMergingTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    public function test_detect_finds_similar_names_of_same_type(): void
    {
        Entity::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Acme Corporation',
            'canonical_name' => 'acme corporation',
            'type' => 'company',
            'mention_count' => 10,
        ]);

        Entity::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Acme Corp',
            'canonical_name' => 'acme corp',
            'type' => 'company',
            'mention_count' => 3,
        ]);

        $action = app(DetectDuplicateEntitiesAction::class);
        $candidates = $action->execute($this->team->id, 0.7);

        $this->assertNotEmpty($candidates);
        $this->assertArrayHasKey('canonical_id', $candidates[0]);
        $this->assertArrayHasKey('duplicate_id', $candidates[0]);
        $this->assertArrayHasKey('confidence', $candidates[0]);
        $this->assertGreaterThanOrEqual(0.7, $candidates[0]['confidence']);
    }

    public function test_detect_does_not_match_different_types(): void
    {
        Entity::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Apple',
            'canonical_name' => 'apple',
            'type' => 'company',
            'mention_count' => 5,
        ]);

        Entity::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Apple',
            'canonical_name' => 'apple',
            'type' => 'product',
            'mention_count' => 5,
        ]);

        $action = app(DetectDuplicateEntitiesAction::class);
        $candidates = $action->execute($this->team->id, 0.99);

        // Different types — should NOT be a merge candidate
        $this->assertEmpty($candidates);
    }

    public function test_merge_redirects_kg_edges_from_duplicate_to_canonical(): void
    {
        $canonical = Entity::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Acme Corp',
            'type' => 'company',
            'mention_count' => 10,
        ]);

        $duplicate = Entity::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Acme Corporation',
            'type' => 'company',
            'mention_count' => 3,
        ]);

        $other = Entity::factory()->create(['team_id' => $this->team->id]);

        // Edge from duplicate
        KgEdge::create([
            'team_id' => $this->team->id,
            'source_entity_id' => $duplicate->id,
            'target_entity_id' => $other->id,
            'edge_type' => 'relates_to',
            'relation_type' => 'partners_with',
            'fact' => 'Acme Corporation partners with other',
            'valid_at' => now(),
        ]);

        // Edge to duplicate
        KgEdge::create([
            'team_id' => $this->team->id,
            'source_entity_id' => $other->id,
            'target_entity_id' => $duplicate->id,
            'edge_type' => 'relates_to',
            'relation_type' => 'acquired_by',
            'fact' => 'Other acquired by Acme Corporation',
            'valid_at' => now(),
        ]);

        $action = app(MergeEntitiesAction::class);
        $action->execute($this->team->id, $canonical->id, $duplicate->id);

        // All edges should now point to canonical
        $this->assertDatabaseHas('kg_edges', [
            'source_entity_id' => $canonical->id,
            'relation_type' => 'partners_with',
        ]);

        $this->assertDatabaseHas('kg_edges', [
            'target_entity_id' => $canonical->id,
            'relation_type' => 'acquired_by',
        ]);
    }

    public function test_merge_deletes_the_duplicate_entity(): void
    {
        $canonical = Entity::factory()->create([
            'team_id' => $this->team->id,
            'type' => 'person',
            'mention_count' => 10,
        ]);

        $duplicate = Entity::factory()->create([
            'team_id' => $this->team->id,
            'type' => 'person',
            'mention_count' => 2,
        ]);

        $duplicateId = $duplicate->id;

        $action = app(MergeEntitiesAction::class);
        $action->execute($this->team->id, $canonical->id, $duplicateId);

        $this->assertDatabaseMissing('entities', ['id' => $duplicateId]);
    }

    public function test_merge_sums_mention_counts(): void
    {
        $canonical = Entity::factory()->create([
            'team_id' => $this->team->id,
            'type' => 'person',
            'mention_count' => 10,
        ]);

        $duplicate = Entity::factory()->create([
            'team_id' => $this->team->id,
            'type' => 'person',
            'mention_count' => 5,
        ]);

        $action = app(MergeEntitiesAction::class);
        $action->execute($this->team->id, $canonical->id, $duplicate->id);

        $canonical->refresh();
        $this->assertEquals(15, $canonical->mention_count);
    }

    public function test_merge_fails_if_entity_belongs_to_different_team(): void
    {
        $otherTeam = Team::factory()->create();

        $canonical = Entity::factory()->create(['team_id' => $this->team->id, 'type' => 'person']);
        $foreign = Entity::factory()->create(['team_id' => $otherTeam->id, 'type' => 'person']);

        $this->expectException(ModelNotFoundException::class);

        $action = app(MergeEntitiesAction::class);
        $action->execute($this->team->id, $canonical->id, $foreign->id);
    }
}
