<?php

namespace Tests\Feature\Domain\KnowledgeGraph;

use App\Domain\KnowledgeGraph\Actions\AddKnowledgeFactAction;
use App\Domain\KnowledgeGraph\Actions\DetectContradictionAction;
use App\Domain\KnowledgeGraph\Actions\NormalizeKnowledgeInputAction;
use App\Domain\KnowledgeGraph\Enums\EntityType;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\KnowledgeGraph\Services\TemporalKnowledgeGraphService;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Entity;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    private function requirePgvector(): void
    {
        if (config('database.default') !== 'pgsql' || ! Schema::hasColumn('kg_edges', 'fact_embedding')) {
            $this->markTestSkipped('Requires PostgreSQL with pgvector extension (fact_embedding column)');
        }
    }

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
            'team_id' => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type' => 'works_at',
            'fact' => 'Alice works at Acme Corp',
            'valid_at' => now()->subDays(10),
            'invalid_at' => null,
        ], $attributes));
    }

    // ─── AddKnowledgeFactAction ─────────────────────────────────────────────────

    public function test_creates_entities_and_an_edge_when_adding_a_fact(): void
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
            skipNormalization: true,
        );

        $this->assertDatabaseHas('kg_edges', [
            'team_id' => $this->team->id,
            'relation_type' => 'works_at',
            'fact' => 'Alice Chen is VP Engineering at Acme Corp',
            'invalid_at' => null,
        ]);

        $this->assertDatabaseHas('entities', [
            'team_id' => $this->team->id,
            'canonical_name' => 'alice chen',
            'type' => 'person',
        ]);

        $this->assertDatabaseHas('entities', [
            'team_id' => $this->team->id,
            'canonical_name' => 'acme corp',
            'type' => 'company',
        ]);

        $this->assertNotNull($edge->id);
        $this->assertEquals('works_at', $edge->relation_type);
    }

    public function test_reuses_existing_entities_instead_of_creating_duplicates(): void
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
            skipNormalization: true,
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
            skipNormalization: true,
        );

        // Alice Chen entity should only exist once
        $this->assertEquals(1, Entity::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('canonical_name', 'alice chen')
            ->count());
    }

    public function test_normalises_relation_type_to_snake_case(): void
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
            skipNormalization: true,
        );

        $this->assertEquals('has_price', $edge->relation_type);
    }

    public function test_proceeds_without_embedding_when_prism_fails(): void
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
            skipNormalization: true,
        );

        // Edge is still created, just without an embedding
        $this->assertDatabaseHas('kg_edges', [
            'team_id' => $this->team->id,
            'fact' => 'Bob works at Beta Corp',
            'invalid_at' => null,
        ]);
        $this->assertNull($edge->fact_embedding);
    }

    // ─── TemporalKnowledgeGraphService ─────────────────────────────────────────

    public function test_returns_only_current_facts_for_an_entity(): void
    {
        $source = Entity::factory()->create(['team_id' => $this->team->id]);
        $target = Entity::factory()->create(['team_id' => $this->team->id]);

        // Current fact
        KgEdge::create([
            'team_id' => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type' => 'works_at',
            'fact' => 'Current fact',
            'valid_at' => now()->subDay(),
            'invalid_at' => null,
        ]);

        // Invalidated (historical) fact
        KgEdge::create([
            'team_id' => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type' => 'works_at',
            'fact' => 'Old fact',
            'valid_at' => now()->subMonth(),
            'invalid_at' => now()->subDay(),
        ]);

        $service = app(TemporalKnowledgeGraphService::class);
        $facts = $service->getCurrentFacts($this->team->id, $source->id);

        $this->assertCount(1, $facts);
        $this->assertEquals('Current fact', $facts->first()->fact);
    }

    public function test_returns_full_timeline_including_invalidated_facts(): void
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

    public function test_returns_point_in_time_facts(): void
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

    public function test_contradiction_detection_skips_when_no_candidates_exist(): void
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

    public function test_contradiction_detection_invalidates_conflicting_edge(): void
    {
        $this->requirePgvector();

        $source = Entity::factory()->create(['team_id' => $this->team->id]);
        $target = Entity::factory()->create(['team_id' => $this->team->id]);
        $oldEdgeId = Str::uuid()->toString();

        // An existing fact with an embedding — same direction as new vector so cosine > 0.6
        $existingVector = array_fill(0, 3, 0.7);
        KgEdge::create([
            'id' => $oldEdgeId,
            'team_id' => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type' => 'works_at',
            'fact' => 'Alice works at Acme Corp',
            'fact_embedding' => $existingVector,
            'valid_at' => now()->subMonth(),
            'invalid_at' => null,
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
            'id' => $oldEdgeId,
            'invalid_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_contradiction_detection_skips_low_similarity_candidates(): void
    {
        $this->requirePgvector();

        $source = Entity::factory()->create(['team_id' => $this->team->id]);
        $target = Entity::factory()->create(['team_id' => $this->team->id]);

        // Existing fact with orthogonal embedding → cosine = 0 → below threshold
        KgEdge::create([
            'team_id' => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'relation_type' => 'works_at',
            'fact' => 'Alice works at Acme Corp',
            'fact_embedding' => [1.0, 0.0, 0.0],
            'valid_at' => now()->subMonth(),
            'invalid_at' => null,
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

    // ─── EntityType Enum ──────────────────────────────────────────────────────

    public function test_entity_type_enum_includes_expanded_types(): void
    {
        $values = EntityType::values();
        $this->assertContains('person', $values);
        $this->assertContains('company', $values);
        $this->assertContains('technology', $values);
        $this->assertContains('event', $values);
        $this->assertContains('concept', $values);
        $this->assertContains('process', $values);
        $this->assertContains('organization', $values);
    }

    public function test_entity_type_defaults_to_topic_for_unknown_values(): void
    {
        $this->assertEquals(EntityType::Topic, EntityType::fromStringOrDefault('unknown_type'));
        $this->assertEquals(EntityType::Topic, EntityType::fromStringOrDefault(''));
    }

    public function test_expanded_entity_types_accepted_by_add_fact(): void
    {
        $this->fakeEmbedding();
        $action = app(AddKnowledgeFactAction::class);

        $edge = $action->execute(
            teamId: $this->team->id,
            sourceName: 'Laravel',
            sourceType: 'technology',
            relationType: 'supports',
            targetName: 'PHP 8.4',
            targetType: 'technology',
            fact: 'Laravel supports PHP 8.4',
            skipNormalization: true,
        );

        $this->assertDatabaseHas('entities', [
            'team_id' => $this->team->id,
            'canonical_name' => 'laravel',
            'type' => 'technology',
        ]);

        $this->assertNotNull($edge->id);
    }

    // ─── NormalizeKnowledgeInputAction ─────────────────────────────────────────

    public function test_normalize_uses_fallback_when_llm_unavailable(): void
    {
        // Mock gateway to throw — simulates LLM unavailability
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andThrow(new \RuntimeException('No API key'));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = app(NormalizeKnowledgeInputAction::class);

        $result = $action->execute(
            teamId: $this->team->id,
            sourceName: ' Alice Chen ',
            sourceType: 'person',
            relationType: 'Works At',
            targetName: ' Acme Corp ',
            targetType: 'company',
            fact: 'Alice works at Acme',
        );

        $this->assertEquals('Alice Chen', $result['source_name']);
        $this->assertEquals('person', $result['source_type']);
        $this->assertEquals('works_at', $result['relation_type']);
        $this->assertEquals('Acme Corp', $result['target_name']);
        $this->assertTrue($result['validation']['valid']);
    }

    public function test_normalize_resolves_entity_to_existing_match(): void
    {
        // Create an existing entity
        $existing = Entity::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Acme Corporation',
            'canonical_name' => 'acme corporation',
            'type' => 'company',
            'mention_count' => 5,
        ]);

        // Mock gateway to return normalization suggesting the existing entity
        $normalizedJson = json_encode([
            'source_name' => 'Alice Chen',
            'source_type' => 'person',
            'source_matched_entity_id' => null,
            'target_name' => 'Acme Corporation',
            'target_type' => 'company',
            'target_matched_entity_id' => $existing->id,
            'relation_type' => 'works_at',
            'fact' => 'Alice Chen works at Acme Corporation',
            'valid' => true,
            'validation_reason' => null,
        ]);

        $responseMock = new AiResponseDTO(
            content: $normalizedJson,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        );

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn($responseMock);
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = app(NormalizeKnowledgeInputAction::class);

        $result = $action->execute(
            teamId: $this->team->id,
            sourceName: 'Alice Chen',
            sourceType: 'person',
            relationType: 'works at',
            targetName: 'Acme Corp',
            targetType: 'company',
            fact: 'Alice works at Acme',
        );

        $this->assertEquals('Acme Corporation', $result['target_name']);
        $this->assertEquals($existing->id, $result['target_matched_entity_id']);
    }

    public function test_normalize_suggests_better_entity_type(): void
    {
        $responseMock = new AiResponseDTO(
            content: json_encode([
                'source_name' => 'Laravel',
                'source_type' => 'technology',
                'source_matched_entity_id' => null,
                'target_name' => 'PHP 8.4',
                'target_type' => 'technology',
                'target_matched_entity_id' => null,
                'relation_type' => 'supports',
                'fact' => 'Laravel supports PHP 8.4',
                'valid' => true,
                'validation_reason' => null,
            ]),
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        );

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn($responseMock);
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = app(NormalizeKnowledgeInputAction::class);

        $result = $action->execute(
            teamId: $this->team->id,
            sourceName: 'Laravel',
            sourceType: 'topic',
            relationType: 'supports',
            targetName: 'PHP 8.4',
            targetType: 'topic',
            fact: 'Laravel supports PHP 8.4',
        );

        // LLM should suggest 'technology' instead of 'topic'
        $this->assertEquals('technology', $result['source_type']);
        $this->assertEquals('technology', $result['target_type']);
    }

    public function test_normalize_flags_invalid_fact(): void
    {
        $responseMock = new AiResponseDTO(
            content: json_encode([
                'source_name' => 'Alice',
                'source_type' => 'person',
                'source_matched_entity_id' => null,
                'target_name' => 'Purple',
                'target_type' => 'topic',
                'target_matched_entity_id' => null,
                'relation_type' => 'works_at',
                'fact' => 'Alice works at Purple',
                'valid' => false,
                'validation_reason' => 'Fact does not match the relation structure: "works_at" expects an organization target',
            ]),
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        );

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn($responseMock);
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = app(NormalizeKnowledgeInputAction::class);

        $result = $action->execute(
            teamId: $this->team->id,
            sourceName: 'Alice',
            sourceType: 'person',
            relationType: 'works_at',
            targetName: 'Purple',
            targetType: 'topic',
            fact: 'sdkjfhskdjf nonsense text',
        );

        $this->assertFalse($result['validation']['valid']);
        $this->assertNotNull($result['validation']['reason']);
    }

    // ─── Integration: Normalization + AddFact ─────────────────────────────────

    public function test_add_fact_with_normalization_stores_validation_warning(): void
    {
        // Mock normalization to flag as invalid
        $normalizeGateway = Mockery::mock(AiGatewayInterface::class);
        $normalizeGateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: json_encode([
                'source_name' => 'Alice',
                'source_type' => 'person',
                'source_matched_entity_id' => null,
                'target_name' => 'Gibberish',
                'target_type' => 'topic',
                'target_matched_entity_id' => null,
                'relation_type' => 'works_at',
                'fact' => 'Alice works at Gibberish',
                'valid' => false,
                'validation_reason' => 'Incoherent fact',
            ]),
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 0, completionTokens: 0, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 10,
        ));
        $this->app->instance(AiGatewayInterface::class, $normalizeGateway);

        $this->fakeEmbedding();

        $action = app(AddKnowledgeFactAction::class);
        $edge = $action->execute(
            teamId: $this->team->id,
            sourceName: 'Alice',
            sourceType: 'person',
            relationType: 'works_at',
            targetName: 'Gibberish',
            targetType: 'topic',
            fact: 'sdkjfhskdjf nonsense',
        );

        // Fact is still stored (we don't reject), but with a warning
        $this->assertNotNull($edge->id);
        $this->assertEquals('Incoherent fact', $edge->attributes['validation_warning'] ?? null);
    }
}
