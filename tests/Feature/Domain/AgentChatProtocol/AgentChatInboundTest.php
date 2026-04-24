<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\AgentChatProtocol\Enums\MessageType;
use App\Domain\AgentChatProtocol\Events\ChatMessageReceived;
use App\Domain\AgentChatProtocol\Services\HmacJwtVerifier;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentChatInboundTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test',
            'slug' => 'test-inbound',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_inbound_chat_with_sanctum_auth_accepts_message(): void
    {
        Event::fake();
        $agent = $this->publishAgent(AgentChatVisibility::Private);

        Sanctum::actingAs($this->user, ['*']);

        $payload = $this->validPayload();
        $response = $this->postJson('/api/v1/agents/'.$agent->id.'/chat', $payload);

        $response->assertStatus(202);
        $response->assertJsonStructure(['ack', 'message_id', 'status']);
        Event::assertDispatched(ChatMessageReceived::class);

        $this->assertDatabaseHas('agent_chat_messages', [
            'agent_id' => $agent->id,
            'message_type' => MessageType::ChatMessage->value,
            'direction' => 'inbound',
        ]);
    }

    public function test_inbound_chat_rejects_duplicate_msg_id(): void
    {
        $agent = $this->publishAgent(AgentChatVisibility::Private);
        Sanctum::actingAs($this->user, ['*']);

        $payload = $this->validPayload();

        $first = $this->postJson('/api/v1/agents/'.$agent->id.'/chat', $payload);
        $first->assertStatus(202);

        $second = $this->postJson('/api/v1/agents/'.$agent->id.'/chat', $payload);
        $second->assertStatus(409);
    }

    public function test_inbound_chat_with_public_jwt_accepts(): void
    {
        $agent = $this->publishAgent(AgentChatVisibility::Public);
        $agent->update(['chat_protocol_secret' => 'test-hmac-secret']);

        $token = app(HmacJwtVerifier::class)->sign(['sub' => 'ext:1'], 'test-hmac-secret', 60);

        $payload = $this->validPayload();
        $response = $this->postJson('/api/v1/agents/'.$agent->id.'/chat', $payload, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(202);
    }

    public function test_inbound_chat_with_public_visibility_missing_jwt_returns_401(): void
    {
        $agent = $this->publishAgent(AgentChatVisibility::Public);
        $agent->update(['chat_protocol_secret' => 'test-hmac-secret']);

        $response = $this->postJson('/api/v1/agents/'.$agent->id.'/chat', $this->validPayload());

        $response->assertStatus(401);
    }

    public function test_inbound_chat_with_disabled_agent_returns_404(): void
    {
        $agent = $this->publishAgent(AgentChatVisibility::Private);
        $agent->update(['chat_protocol_enabled' => false]);

        Sanctum::actingAs($this->user, ['*']);
        $response = $this->postJson('/api/v1/agents/'.$agent->id.'/chat', $this->validPayload());

        $response->assertStatus(404);
    }

    public function test_inbound_chat_schema_error_returns_400(): void
    {
        $agent = $this->publishAgent(AgentChatVisibility::Private);
        Sanctum::actingAs($this->user, ['*']);

        $payload = $this->validPayload();
        $payload['msg_id'] = 'not-a-uuid';

        $response = $this->postJson('/api/v1/agents/'.$agent->id.'/chat', $payload);
        $response->assertStatus(400);
    }

    public function test_cross_team_access_returns_404(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-team-x',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $otherUser->update(['current_team_id' => $otherTeam->id]);
        $otherTeam->users()->attach($otherUser, ['role' => 'owner']);

        $agent = $this->publishAgent(AgentChatVisibility::Private);
        Sanctum::actingAs($otherUser, ['*']);

        $response = $this->postJson('/api/v1/agents/'.$agent->id.'/chat', $this->validPayload());
        $response->assertStatus(404);
    }

    private function publishAgent(AgentChatVisibility $visibility): Agent
    {
        return Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Inbound Test',
            'slug' => 'inbound-'.Str::random(6),
            'role' => 'assistant',
            'goal' => 'help',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'chat_protocol_enabled' => true,
            'chat_protocol_visibility' => $visibility->value,
            'chat_protocol_slug' => 'inb-'.Str::random(6),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'msg_id' => (string) Str::uuid7(),
            'session_id' => (string) Str::uuid7(),
            'from' => 'external:test',
            'to' => 'fleetq:test-agent',
            'content' => 'hello',
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
