<?php

namespace Tests\Feature\Domain\KnowledgeGraph;

use App\Domain\KnowledgeGraph\Actions\AddKnowledgeFactAction;
use App\Domain\KnowledgeGraph\Actions\DetectContradictionAction;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\KnowledgeGraph\Services\TemporalKnowledgeGraphService;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Entity;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Tests\TestCase;

class KnowledgeGraphTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function fakeEmbedding(array $vector = []): void
    {
        $vector = $vector ?: array_fill(0, 1536, 0.1);

        Prism::fake([
            new EmbeddingResponse(
                embeddings: [new Embedding($vector)],
                usage: new EmbeddingsUsage(tokens: 10),
                meta: new Meta(id: 'test', model: 'text-embedding-3-small'),
            ),
        ]);
    }

    private function createEdge(array $attributes = []): KgEdge
    {
        $source = Entity::factory()->create(['team_id' => $this->team->id]);
        $target = Entity::factory()->create(['team_id' => $this->team->id]);

        return KgEdge::create(array_merge([
            'team_id'          => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type'    => 'works_at',
            'fact'             => 'Alice works at Acme Corp',
            'valid_at'         => now()->subDays(10),
            'invalid_at'       => null,
        ], $attributes));
    }

    // ─── AddKnowledgeFactAction ─────────────────────────────────────────────────

    /** @test */
    public function it_creates_entities_and_an_edge_when_adding_a_fact(): void
    {
        $this->fakeEmbedding();
        // DetectContradictionAction needs AiGatewayInterface but will find no candidates, so it won't call LLM
        $action = app(AddKnowledgeFactAction::class);

        $edge = $action->execute(
            teamId: $this->team->id,
            sourceName: 'Alice Chen',
            sourceType: 'person',
            relationType: 'works_at',
            targetName: 'Acme Corp',
            targetType: 'company',
            fact: 'Alice Chen is VP Engineering at Acme Corp',
        );

        $this->assertDatabaseHas('kg_edges', [
            'team_id'       => $this->team->id,
            'relation_type' => 'works_at',
            'fact'          => 'Alice Chen is VP Engineering at Acme Corp',
            'invalid_at'    => null,
        ]);

        $this->assertDatabaseHas('entities', [
            'team_id'        => $this->team->id,
            'canonical_name' => 'alice chen',
            'type'           => 'person',
        ]);

        $this->assertDatabaseHas('entities', [
            'team_id'        => $this->team->id,
            'canonical_name' => 'acme corp',
            'type'           => 'company',
        ]);

        $this->assertNotNull($edge->id);
        $this->assertEquals('works_at', $edge->relation_type);
    }

    /** @test */
    public function it_reuses_existing_entities_instead_of_creating_duplicates(): void
    {
        $this->fakeEmbedding();
        $action = app(AddKnowledgeFactAction::class);

        $action->execute(
            teamId: $this->team->id,
            sourceName: 'Alice Chen',
            sourceType: 'person',
            relationType: 'works_at',
            targetName: 'Acme Corp',
            targetType: 'company',
            fact: 'Alice Chen works at Acme Corp',
        );

        $this->fakeEmbedding();

        $action->execute(
            teamId: $this->team->id,
            sourceName: 'Alice Chen',
            sourceType: 'person',
            relationType: 'has_title',
            targetName: 'VP Engineering',
            targetType: 'topic',
            fact: 'Alice Chen holds the title VP Engineering',
        );

        // Alice Chen entity should only exist once
        $this->assertEquals(1, Entity::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('canonical_name', 'alice chen')
            ->count());
    }

    /** @test */
    public function it_normalises_relation_type_to_snake_case(): void
    {
        $this->fakeEmbedding();
        $action = app(AddKnowledgeFactAction::class);

        $edge = $action->execute(
            teamId: $this->team->id,
            sourceName: 'Alice Chen',
            sourceType: 'person',
            relationType: 'Has Price',
            targetName: '$99/month',
            targetType: 'topic',
            fact: 'Alice Chen has_price $99/month',
        );

        $this->assertEquals('has_price', $edge->relation_type);
    }

    /** @test */
    public function it_proceeds_without_embedding_when_prism_fails(): void
    {
        // Prism will throw — embedding generation silently fails
        Prism::shouldReceive('embeddings')->andThrow(new \RuntimeException('No API key'));

        $action = app(AddKnowledgeFactAction::class);

        $edge = $action->execute(
            teamId: $this->team->id,
            sourceName: 'Bob',
            sourceType: 'person',
            relationType: 'works_at',
            targetName: 'Beta Corp',
            targetType: 'company',
            fact: 'Bob works at Beta Corp',
        );

        // Edge is still created, just without an embedding
        $this->assertDatabaseHas('kg_edges', [
            'team_id'    => $this->team->id,
            'fact'       => 'Bob works at Beta Corp',
            'invalid_at' => null,
        ]);
        $this->assertNull($edge->fact_embedding);
    }

    // ─── TemporalKnowledgeGraphService ─────────────────────────────────────────

    /** @test */
    public function it_returns_only_current_facts_for_an_entity(): void
    {
        $source = Entity::factory()->create(['team_id' => $this->team->id]);
        $target = Entity::factory()->create(['team_id' => $this->team->id]);

        // Current fact
        KgEdge::create([
            'team_id'          => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type'    => 'works_at',
            'fact'             => 'Current fact',
            'valid_at'         => now()->subDay(),
            'invalid_at'       => null,
        ]);

        // Invalidated (historical) fact
        KgEdge::create([
            'team_id'          => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type'    => 'works_at',
            'fact'             => 'Old fact',
            'valid_at'         => now()->subMonth(),
            'invalid_at'       => now()->subDay(),
        ]);

        $service = app(TemporalKnowledgeGraphService::class);
        $facts = $service->getCurrentFacts($this->team->id, $source->id);

        $this->assertCount(1, $facts);
        $this->assertEquals('Current fact', $facts->first()->fact);
    }

    /** @test */
    public function it_returns_full_timeline_including_invalidated_facts(): void
    {
        $source = Entity::factory()->create(['team_id' => $this->team->id]);
        $target = Entity::factory()->create(['team_id' => $this->team->id]);

        KgEdge::create([
            'team_id' => $this->team->id, 'source_entity_id' => $source->id,
            'target_entity_id' => $target->id, 'relation_type' => 'works_at',
            'fact' => 'Current', 'valid_at' => now()->subDay(), 'invalid_at' => null,
        ]);

        KgEdge::create([
            'team_id' => $this->team->id, 'source_entity_id' => $source->id,
            'target_entity_id' => $target->id, 'relation_type' => 'works_at',
            'fact' => 'Historical', 'valid_at' => now()->subMonth(), 'invalid_at' => now()->subDay(),
        ]);

        $service = app(TemporalKnowledgeGraphService::class);
        $timeline = $service->getEntityTimeline($this->team->id, $source->id);

        $this->assertCount(2, $timeline);
    }

    /** @test */
    public function it_returns_point_in_time_facts(): void
    {
        $source = Entity::factory()->create(['team_id' => $this->team->id]);
        $target = Entity::factory()->create(['team_id' => $this->team->id]);

        $past = now()->subDays(5);

        // Was valid in the past, now invalidated
        KgEdge::create([
            'team_id' => $this->team->id, 'source_entity_id' => $source->id,
            'target_entity_id' => $target->id, 'relation_type' => 'works_at',
            'fact' => 'Was valid then', 'valid_at' => now()->subDays(20),
            'invalid_at' => now()->subDays(3),
        ]);

        // Currently valid but started after the past query date
        KgEdge::create([
            'team_id' => $this->team->id, 'source_entity_id' => $source->id,
            'target_entity_id' => $target->id, 'relation_type' => 'works_at',
            'fact' => 'Started after', 'valid_at' => now()->subDays(2), 'invalid_at' => null,
        ]);

        $service = app(TemporalKnowledgeGraphService::class);
        $facts = $service->getFactsAt($this->team->id, $source->id, $past);

        $this->assertCount(1, $facts);
        $this->assertEquals('Was valid then', $facts->first()->fact);
    }

    // ─── DetectContradictionAction ──────────────────────────────────────────────

    /** @test */
    public function contradiction_detection_skips_when_no_candidates_exist(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $source = Entity::factory()->create(['team_id' => $this->team->id]);

        $action = app(DetectContradictionAction::class);
        $invalidated = $action->execute(
            teamId: $this->team->id,
            sourceEntityId: $source->id,
            relationType: 'works_at',
            newFact: 'Alice works at Beta Corp',
            newFactEmbeddingStr: '['.implode(',', array_fill(0, 3, 0.5)).']',
            validAt: now(),
        );

        $this->assertEmpty($invalidated);
    }

    /** @test */
    public function contradiction_detection_invalidates_conflicting_edge(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL (fact_embedding column)');
        }

        $source = Entity::factory()->create(['team_id' => $this->team->id]);
        $target = Entity::factory()->create(['team_id' => $this->team->id]);
        $oldEdgeId = Str::uuid()->toString();

        // An existing fact with an embedding — same direction as new vector so cosine > 0.6
        $existingVector = array_fill(0, 3, 0.7);
        KgEdge::create([
            'id'               => $oldEdgeId,
            'team_id'          => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type'    => 'works_at',
            'fact'             => 'Alice works at Acme Corp',
            'fact_embedding'   => $existingVector,
            'valid_at'         => now()->subMonth(),
            'invalid_at'       => null,
        ]);

        // LLM mock: say old edge is contradicted
        $responseMock = new AiResponseDTO(
            content: json_encode([$oldEdgeId]),
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 0, completionTokens: 0, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 10,
        );

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn($responseMock);
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $newVector = array_fill(0, 3, 0.8);

        $action = app(DetectContradictionAction::class);
        $invalidated = $action->execute(
            teamId: $this->team->id,
            sourceEntityId: $source->id,
            relationType: 'works_at',
            newFact: 'Alice works at Beta Corp',
            newFactEmbeddingStr: '['.implode(',', $newVector).']',
            validAt: now(),
        );

        $this->assertContains($oldEdgeId, $invalidated);
        $this->assertDatabaseHas('kg_edges', [
            'id'         => $oldEdgeId,
            'invalid_at' => now()->toDateTimeString(),
        ]);
    }

    /** @test */
    public function contradiction_detection_skips_low_similarity_candidates(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL (fact_embedding column)');
        }

        $source = Entity::factory()->create(['team_id' => $this->team->id]);
        $target = Entity::factory()->create(['team_id' => $this->team->id]);

        // Existing fact with orthogonal embedding → cosine = 0 → below threshold
        KgEdge::create([
            'team_id'          => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type'    => 'works_at',
            'fact'             => 'Alice works at Acme Corp',
            'fact_embedding'   => [1.0, 0.0, 0.0],
            'valid_at'         => now()->subMonth(),
            'invalid_at'       => null,
        ]);

        // LLM should NOT be called since cosine similarity < 0.6
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = app(DetectContradictionAction::class);
        $invalidated = $action->execute(
            teamId: $this->team->id,
            sourceEntityId: $source->id,
            relationType: 'works_at',
            newFact: 'Alice works at Beta Corp',
            newFactEmbeddingStr: '[0.0,1.0,0.0]', // orthogonal vector
            validAt: now(),
        );

        $this->assertEmpty($invalidated);
    }
}
