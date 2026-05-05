<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Actions\DispatchChatMessageAction;
use App\Domain\AgentChatProtocol\Actions\InstallFromAgentverseAction;
use App\Domain\AgentChatProtocol\DTOs\ChatMessageDTO;
use App\Domain\AgentChatProtocol\Enums\AdapterKind;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Services\AgentverseClient;
use App\Domain\AgentChatProtocol\Services\AgentverseEnvelopeMapper;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentverseConsumerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'AV Consumer',
            'slug' => 'av-consumer-'.Str::random(4),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
    }

    public function test_client_works_without_credential(): void
    {
        $client = AgentverseClient::forTeam($this->team->id);
        $this->assertInstanceOf(AgentverseClient::class, $client);
    }

    public function test_list_agents_hits_v1_search_with_correct_body_shape(): void
    {
        Http::fake([
            AgentverseClient::SEARCH_URL => Http::response([
                'agents' => [['address' => 'agent1qtest', 'name' => 'Test']],
                'offset' => 0,
                'limit' => 25,
                'num_hits' => 1,
                'total' => 1,
                'search_id' => 'abc',
            ], 200),
        ]);

        $agents = AgentverseClient::forTeam($this->team->id)->listAgents([
            'search_text' => 'weather',
            'limit' => 10,
        ]);

        $this->assertCount(1, $agents);
        Http::assertSent(function ($req) {
            return $req->url() === AgentverseClient::SEARCH_URL
                && $req->method() === 'POST'
                && $req['search_text'] === 'weather'
                && (int) $req['limit'] === 10
                && (int) $req['offset'] === 0;
        });
    }

    public function test_envelope_wrap_produces_uuid4_session_and_string_payload(): void
    {
        $mapper = new AgentverseEnvelopeMapper;
        $dto = new ChatMessageDTO(
            msgId: (string) Str::uuid7(),
            sessionId: (string) Str::uuid7(),
            from: 'fleetq:team:42',
            to: 'agent1qremote',
            content: 'hello',
            timestamp: now()->toIso8601String(),
        );

        $envelope = $mapper->wrap($dto, 'fleetq:team:42', 'agent1qremote');

        $this->assertSame(1, $envelope['version']);
        $this->assertSame('fleetq:team:42', $envelope['sender']);
        $this->assertSame('agent1qremote', $envelope['target']);
        $this->assertIsString($envelope['payload']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $envelope['session'],
        );

        $decoded = json_decode($envelope['payload'], true);
        $this->assertSame('hello', $decoded['content']);
    }

    public function test_envelope_session_mapping_is_deterministic(): void
    {
        $mapper = new AgentverseEnvelopeMapper;
        $dto1 = new ChatMessageDTO(
            msgId: (string) Str::uuid7(),
            sessionId: 'same-session-token',
            from: 'x', to: 'y', content: 'a', timestamp: now()->toIso8601String(),
        );
        $dto2 = new ChatMessageDTO(
            msgId: (string) Str::uuid7(),
            sessionId: 'same-session-token',
            from: 'x', to: 'y', content: 'b', timestamp: now()->toIso8601String(),
        );

        $this->assertSame(
            $mapper->wrap($dto1, 'c', 't')['session'],
            $mapper->wrap($dto2, 'c', 't')['session'],
        );
    }

    public function test_install_from_agentverse_uses_search_and_maps_real_fields(): void
    {
        Http::fake([
            AgentverseClient::SEARCH_URL => Http::response([
                'agents' => [
                    [
                        'address' => 'agent1qweather',
                        'name' => 'Weather Agent',
                        'handle' => null,
                        'readme' => '# Weather Agent',
                        'description' => '',
                        'rating' => 4.5,
                        'featured' => true,
                        'category' => 'utility',
                        'avatar_href' => 'https://example.com/avatar.png',
                        'total_interactions' => 46093,
                        'domain' => 'weather.fetch.ai',
                        'type' => 'hosted',
                        'protocols' => ['proto:deadbeef'],
                        'status' => 'active',
                    ],
                ],
            ], 200),
        ]);

        $agent = app(InstallFromAgentverseAction::class)->execute(
            teamId: $this->team->id,
            agentAddress: 'agent1qweather',
        );

        $this->assertSame('Weather Agent', $agent->name);
        $this->assertSame('agent1qweather', $agent->agent_address);
        $this->assertSame(AdapterKind::AgentverseMailbox->value, $agent->adapter_kind->value);
        $this->assertSame(ExternalAgentStatus::Active->value, $agent->status->value);
        $this->assertSame(4.5, $agent->capabilities['rating']);
        $this->assertTrue($agent->capabilities['featured']);
        $this->assertSame('utility', $agent->capabilities['category']);
        $this->assertSame(46093, $agent->capabilities['total_interactions']);
        $this->assertSame('https://example.com/avatar.png', $agent->capabilities['avatar_href']);
        $this->assertNull($agent->capabilities['handle']);
    }

    public function test_install_rejects_unknown_address(): void
    {
        Http::fake([
            AgentverseClient::SEARCH_URL => Http::response([
                'agents' => [
                    ['address' => 'agent1qother', 'name' => 'Other'],
                ],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found in public search');

        app(InstallFromAgentverseAction::class)->execute(
            teamId: $this->team->id,
            agentAddress: 'agent1qmissing',
        );
    }

    public function test_install_is_idempotent_for_same_address(): void
    {
        Http::fake([
            AgentverseClient::SEARCH_URL => Http::response([
                'agents' => [['address' => 'agent1qidem', 'name' => 'Idem', 'handle' => null]],
            ], 200),
        ]);

        $first = app(InstallFromAgentverseAction::class)->execute($this->team->id, 'agent1qidem');
        $second = app(InstallFromAgentverseAction::class)->execute($this->team->id, 'agent1qidem');

        $this->assertSame($first->id, $second->id);
    }

    public function test_dispatcher_routes_agentverse_mailbox_with_correct_envelope(): void
    {
        Http::fake([
            AgentverseClient::SEARCH_URL => Http::response([
                'agents' => [['address' => 'agent1qmailbox', 'name' => 'Mailbox Test', 'handle' => null]],
            ], 200),
            AgentverseClient::MAILBOX_URL => Http::response([
                'payload' => json_encode([
                    'msg_id' => (string) Str::uuid7(),
                    'content' => 'remote reply via mailbox',
                ]),
            ], 200),
        ]);

        $agent = app(InstallFromAgentverseAction::class)->execute($this->team->id, 'agent1qmailbox');

        $result = app(DispatchChatMessageAction::class)->execute(
            externalAgent: $agent,
            content: 'hello via agentverse',
        );

        $this->assertNotNull($result['remote_response'] ?? null);

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'agents/mailbox/submit')) {
                return false;
            }
            $body = $req->data();

            return isset($body['version'], $body['sender'], $body['target'], $body['session'], $body['payload'])
                && $body['target'] === 'agent1qmailbox'
                && is_string($body['payload'])
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['session']) === 1;
        });
    }
}
