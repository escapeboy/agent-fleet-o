<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentA2ACardTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'A2A Test Team',
            'slug' => 'a2a-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_well_known_show_returns_200_for_public_agent(): void
    {
        $agent = $this->makePublicAgent();

        $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug)
            ->assertOk();
    }

    public function test_well_known_show_returns_capabilities_object(): void
    {
        $agent = $this->makePublicAgent();

        $response = $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug);

        $response->assertOk();
        $response->assertJsonStructure(['capabilities']);
    }

    public function test_well_known_show_returns_skills_key_in_fleetq_extension_or_structure(): void
    {
        $agent = $this->makePublicAgent();

        $response = $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('identifier', $data);
        $this->assertArrayHasKey('name', $data);
    }

    public function test_well_known_show_returns_404_for_nonexistent_slug(): void
    {
        $this->getJson('/.well-known/agents/does-not-exist-xyz')
            ->assertNotFound();
    }

    public function test_well_known_show_returns_404_when_chat_protocol_disabled(): void
    {
        $agent = $this->makePublicAgent();
        $agent->update(['chat_protocol_enabled' => false]);

        $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug)
            ->assertNotFound();
    }

    public function test_a2a_endpoint_returns_schema_version(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $agent = $this->makePublicAgent();

        $this->getJson('/api/v1/agents/'.$agent->id.'/a2a')
            ->assertOk()
            ->assertJsonFragment(['schemaVersion' => '0.3']);
    }

    public function test_a2a_endpoint_returns_capabilities_with_streaming_true(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $agent = $this->makePublicAgent();

        $response = $this->getJson('/api/v1/agents/'.$agent->id.'/a2a');

        $response->assertOk();
        $this->assertTrue($response->json('capabilities.streaming'));
    }

    public function test_a2a_endpoint_returns_skills_array(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $agent = $this->makePublicAgent();

        $response = $this->getJson('/api/v1/agents/'.$agent->id.'/a2a');

        $response->assertOk();
        $response->assertJsonStructure(['skills']);
        $this->assertIsArray($response->json('skills'));
    }

    public function test_a2a_endpoint_returns_404_for_agent_from_other_team(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other A2A Team',
            'slug' => 'other-a2a-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        $otherAgent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $otherTeam->id,
            'name' => 'Other Agent',
            'slug' => 'other-agent-a2a',
            'role' => 'assistant',
            'goal' => 'help',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'chat_protocol_enabled' => true,
            'chat_protocol_visibility' => AgentChatVisibility::Public->value,
            'chat_protocol_slug' => 'other-agent-'.Str::random(6),
        ]);

        $this->getJson('/api/v1/agents/'.$otherAgent->id.'/a2a')
            ->assertStatus(404);
    }

    private function makePublicAgent(): Agent
    {
        return Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'A2A Test Agent '.Str::random(4),
            'slug' => 'a2a-test-agent-'.Str::random(6),
            'role' => 'assistant',
            'goal' => 'help with tasks',
            'backstory' => 'test backstory',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'chat_protocol_enabled' => true,
            'chat_protocol_visibility' => AgentChatVisibility::Public->value,
            'chat_protocol_slug' => 'a2a-agent-'.Str::random(8),
        ]);
    }
}
