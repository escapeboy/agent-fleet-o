<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentManifestPublicTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test',
            'slug' => 'test-manifest',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    public function test_public_manifest_list_returns_public_agents_only(): void
    {
        $this->publishAgent(AgentChatVisibility::Public);
        $this->publishAgent(AgentChatVisibility::Private);

        $response = $this->getJson('/.well-known/agents');

        $response->assertOk();
        $response->assertJsonCount(1, 'agents');
    }

    public function test_public_manifest_show_returns_full_manifest(): void
    {
        $agent = $this->publishAgent(AgentChatVisibility::Public);

        $response = $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug);

        $response->assertOk();
        $response->assertJsonFragment([
            'protocol' => (string) config('agent_chat.protocol_manifest_uri'),
        ]);
        $response->assertJsonStructure([
            'identifier', 'name', 'protocol', 'supported_message_types', 'endpoint', 'auth_scheme',
        ]);
    }

    public function test_private_agent_manifest_returns_404(): void
    {
        $agent = $this->publishAgent(AgentChatVisibility::Private);

        $response = $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug);

        $response->assertNotFound();
    }

    public function test_disabled_agent_not_exposed(): void
    {
        $agent = $this->publishAgent(AgentChatVisibility::Public);
        $agent->update(['chat_protocol_enabled' => false]);

        $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug)->assertNotFound();
    }

    public function test_manifest_does_not_leak_team_id(): void
    {
        $agent = $this->publishAgent(AgentChatVisibility::Public);

        $response = $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug);

        $response->assertOk();
        $this->assertStringNotContainsString((string) $this->team->id, $response->getContent() ?: '');
    }

    private function publishAgent(AgentChatVisibility $visibility): Agent
    {
        return Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Test Agent '.Str::random(4),
            'slug' => 'test-agent-'.Str::random(6),
            'role' => 'assistant',
            'goal' => 'help',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'chat_protocol_enabled' => true,
            'chat_protocol_visibility' => $visibility->value,
            'chat_protocol_slug' => 'agent-'.Str::random(8),
        ]);
    }
}
