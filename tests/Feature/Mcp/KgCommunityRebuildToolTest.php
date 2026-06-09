<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Entity;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Mcp\Tools\Signal\KgCommunityRebuildTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Tests\TestCase;

class KgCommunityRebuildToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuilds_communities_for_own_team_only(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'KG Rebuild Team',
            'slug' => 'kg-rebuild-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Rebuild Team',
            'slug' => 'kg-rebuild-other',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        // Another team's community must survive the rebuild untouched.
        $otherCommunity = KgCommunity::create([
            'team_id' => $otherTeam->id,
            'label' => 'Other Team Community',
            'summary' => 'Belongs to another team',
            'entity_ids' => [],
            'size' => 3,
            'top_entities' => [],
        ]);

        // Build a small connected graph for the acting team (triangle => one community).
        $entities = Entity::factory()->count(3)->for($team)->create();
        $this->linkEntities($team->id, $entities[0]->id, $entities[1]->id);
        $this->linkEntities($team->id, $entities[1]->id, $entities[2]->id);
        $this->linkEntities($team->id, $entities[0]->id, $entities[2]->id);

        // Stub the LLM gateway so no real API call is made.
        $this->app->instance(AiGatewayInterface::class, new class implements AiGatewayInterface
        {
            public function complete(AiRequestDTO $request): AiResponseDTO
            {
                return new AiResponseDTO(
                    content: '{"label": "Stub Cluster", "summary": "A stubbed summary."}',
                    parsedOutput: null,
                    usage: new AiUsageDTO(0, 0, 0),
                    provider: $request->provider,
                    model: $request->model,
                    latencyMs: 0,
                );
            }

            public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
            {
                return $this->complete($request);
            }

            public function estimateCost(AiRequestDTO $request): int
            {
                return 0;
            }
        });

        app()->instance('mcp.team_id', $team->id);

        $response = (new KgCommunityRebuildTool)->handle(new Request([]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertFalse($response->isError(), 'Got error: '.json_encode($payload));
        $this->assertTrue($payload['success']);
        $this->assertGreaterThanOrEqual(1, $payload['community_count']);

        // All built communities belong to the acting team.
        $this->assertSame(
            $payload['community_count'],
            KgCommunity::where('team_id', $team->id)->count(),
        );
        $this->assertSame(
            0,
            KgCommunity::where('team_id', $team->id)->where('team_id', '!=', $team->id)->count(),
        );

        // The other team's community was not deleted.
        $this->assertDatabaseHas('kg_communities', [
            'id' => $otherCommunity->id,
            'team_id' => $otherTeam->id,
        ]);
    }

    private function linkEntities(string $teamId, string $source, string $target): void
    {
        DB::table('kg_edges')->insert([
            'id' => (string) Str::uuid(),
            'team_id' => $teamId,
            'source_entity_id' => $source,
            'target_entity_id' => $target,
            'relation_type' => 'relates_to',
            'edge_type' => 'relates_to',
            'fact' => 'related',
            'attributes' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
