<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExternalAgentCrudTest extends TestCase
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
            'slug' => 'test-ea-crud',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_register_external_agent_fetches_manifest(): void
    {
        Http::fake([
            'https://example.com/api/v1/agents/foo/manifest' => Http::response([
                'identifier' => 'foo',
                'protocol' => 'proto:asi1-v1',
                'supported_message_types' => ['chat_message', 'chat_acknowledgement'],
                'capabilities' => ['streaming' => true, 'async' => false],
                'manifest_version' => 'asi1-v1',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/external-agents', [
            'name' => 'Foo Agent',
            'endpoint_url' => 'https://example.com/api/v1/agents/foo',
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['name' => 'Foo Agent']);

        $record = ExternalAgent::withoutGlobalScopes()->where('team_id', $this->team->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals(ExternalAgentStatus::Active->value, $record->status->value);
        $this->assertContains('chat_message', $record->capabilities['supported_message_types'] ?? []);
    }

    public function test_list_external_agents_scopes_to_team(): void
    {
        ExternalAgent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Mine',
            'slug' => 'mine-'.Str::random(4),
            'endpoint_url' => 'https://example.com/a',
            'status' => ExternalAgentStatus::Active,
        ]);

        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-ea',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        ExternalAgent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $otherTeam->id,
            'name' => 'Theirs',
            'slug' => 'theirs-'.Str::random(4),
            'endpoint_url' => 'https://example.com/b',
            'status' => ExternalAgentStatus::Active,
        ]);

        $response = $this->getJson('/api/v1/external-agents');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Mine']);
    }

    public function test_delete_external_agent_soft_deletes(): void
    {
        $ea = ExternalAgent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Doomed',
            'slug' => 'doomed-'.Str::random(4),
            'endpoint_url' => 'https://example.com/doomed',
            'status' => ExternalAgentStatus::Active,
        ]);

        $response = $this->deleteJson('/api/v1/external-agents/'.$ea->id);
        $response->assertOk();

        $this->assertSoftDeleted('external_agents', ['id' => $ea->id]);
    }
}
