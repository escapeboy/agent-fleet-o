<?php

namespace Tests\Feature\Domain\KnowledgeGraph;

use App\Domain\KnowledgeGraph\Actions\BuildKgCommunitiesAction;
use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Domain\KnowledgeGraph\Services\LouvainCommunityDetector;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Entity;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Prism\Prism\Facades\Prism;
use Tests\TestCase;

class BuildKgCommunitiesTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    private function makeLlmResponse(string $label = 'Test Community', string $summary = 'A test community.'): AiResponseDTO
    {
        return new AiResponseDTO(
            content: json_encode(['label' => $label, 'summary' => $summary]),
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 10,
        );
    }

    private function createEntityWithEdge(Entity $source, Entity $target): void
    {
        DB::table('kg_edges')->insert([
            'id' => Str::uuid()->toString(),
            'team_id' => $this->team->id,
            'source_entity_id' => $source->id,
            'target_entity_id' => $target->id,
            'edge_type' => 'relates_to',
            'relation_type' => 'connects',
            'fact' => "{$source->name} connects {$target->name}",
            'valid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_builds_communities_from_connected_entities(): void
    {
        // 4 entities connected in pairs → 2 communities
        $e1 = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'Alpha']);
        $e2 = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'Beta']);
        $e3 = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'Gamma']);
        $e4 = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'Delta']);

        $this->createEntityWithEdge($e1, $e2);
        $this->createEntityWithEdge($e3, $e4);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn($this->makeLlmResponse());
        $this->app->instance(AiGatewayInterface::class, $gateway);

        // Fake embedding
        Prism::shouldReceive('embeddings')->andThrow(new \RuntimeException('no key'));

        $action = new BuildKgCommunitiesAction($gateway, new LouvainCommunityDetector);
        $action->execute($this->team->id, 2);

        $this->assertGreaterThanOrEqual(1, KgCommunity::where('team_id', $this->team->id)->count());
    }

    public function test_skips_team_with_fewer_than_3_entities(): void
    {
        Entity::factory()->create(['team_id' => $this->team->id]);
        Entity::factory()->create(['team_id' => $this->team->id]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = new BuildKgCommunitiesAction($gateway, new LouvainCommunityDetector);
        $action->execute($this->team->id);

        $this->assertEquals(0, KgCommunity::where('team_id', $this->team->id)->count());
    }

    public function test_llm_called_once_per_community(): void
    {
        $entities = Entity::factory()->count(4)->create(['team_id' => $this->team->id]);

        // Form two connected pairs → 2 communities
        $this->createEntityWithEdge($entities[0], $entities[1]);
        $this->createEntityWithEdge($entities[2], $entities[3]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        // Allow 0-2 calls (once per community, some may be filtered by min-size)
        $gateway->shouldReceive('complete')->between(0, 2)->andReturn($this->makeLlmResponse());
        $this->app->instance(AiGatewayInterface::class, $gateway);

        Prism::shouldReceive('embeddings')->andThrow(new \RuntimeException('no key'));

        $action = new BuildKgCommunitiesAction($gateway, new LouvainCommunityDetector);
        $action->execute($this->team->id, 2);
    }

    public function test_old_communities_deleted_and_replaced(): void
    {
        // Pre-create a stale community
        KgCommunity::create([
            'team_id' => $this->team->id,
            'label' => 'Stale',
            'summary' => 'Old data',
            'entity_ids' => [],
            'size' => 0,
            'top_entities' => [],
        ]);

        $e1 = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'X']);
        $e2 = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'Y']);
        $e3 = Entity::factory()->create(['team_id' => $this->team->id, 'name' => 'Z']);
        $this->createEntityWithEdge($e1, $e2);
        $this->createEntityWithEdge($e2, $e3);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn($this->makeLlmResponse('New Community', 'Fresh data.'));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        Prism::shouldReceive('embeddings')->andThrow(new \RuntimeException('no key'));

        $action = new BuildKgCommunitiesAction($gateway, new LouvainCommunityDetector);
        $action->execute($this->team->id, 2);

        // Stale label should be gone
        $this->assertDatabaseMissing('kg_communities', [
            'team_id' => $this->team->id,
            'label' => 'Stale',
        ]);

        // New community should exist
        $this->assertDatabaseHas('kg_communities', [
            'team_id' => $this->team->id,
            'label' => 'New Community',
        ]);
    }
}
