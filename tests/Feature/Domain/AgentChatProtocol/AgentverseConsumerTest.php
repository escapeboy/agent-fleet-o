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
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
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

    private Credential $credential;

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

        $this->credential = Credential::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'ASI:One API Key',
            'slug' => 'asi-one-'.Str::random(4),
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['api_key' => 'test-asi-key'],
            'metadata' => ['provider' => 'agentverse'],
        ]);
    }

    public function test_client_for_team_resolves_agentverse_credential(): void
    {
        $client = AgentverseClient::forTeam($this->team->id);
        $this->assertNotNull($client);
    }

    public function test_client_returns_null_when_no_agentverse_credential(): void
    {
        $otherTeam = Team::create([
            'name' => 'No Creds',
            'slug' => 'no-creds-'.Str::random(4),
            'owner_id' => $this->team->owner_id,
            'settings' => [],
        ]);

        $this->assertNull(AgentverseClient::forTeam($otherTeam->id));
    }

    public function test_envelope_wrap_produces_expected_shape(): void
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
        $this->assertSame($dto->sessionId, $envelope['session']);
        $this->assertIsArray($envelope['payload']);
        $this->assertSame('hello', $envelope['payload']['content']);
    }

    public function test_envelope_unwrap_rejects_malformed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AgentverseEnvelopeMapper)->unwrap(['incomplete' => true]);
    }

    public function test_install_from_agentverse_creates_external_agent(): void
    {
        Http::fake([
            AgentverseClient::BASE_URL.'/agents/agent1qremote' => Http::response([
                'name' => 'Weather Expert',
                'handle' => '@weather',
                'readme' => 'A helpful weather agent',
                'supported_message_types' => ['chat_message', 'chat_acknowledgement'],
                'ranking_score' => 9.4,
            ], 200),
        ]);

        $agent = app(InstallFromAgentverseAction::class)->execute(
            teamId: $this->team->id,
            agentAddress: 'agent1qremote',
        );

        $this->assertSame('Weather Expert', $agent->name);
        $this->assertSame('agent1qremote', $agent->agent_address);
        $this->assertSame(AdapterKind::AgentverseMailbox->value, $agent->adapter_kind->value);
        $this->assertSame(ExternalAgentStatus::Active->value, $agent->status->value);
        $this->assertSame(9.4, $agent->capabilities['ranking_score']);
    }

    public function test_install_is_idempotent_for_same_address(): void
    {
        Http::fake([
            AgentverseClient::BASE_URL.'/agents/agent1qremote' => Http::response([
                'name' => 'Weather Expert',
                'handle' => '@weather',
            ], 200),
        ]);

        $first = app(InstallFromAgentverseAction::class)->execute(
            teamId: $this->team->id,
            agentAddress: 'agent1qremote',
        );
        $second = app(InstallFromAgentverseAction::class)->execute(
            teamId: $this->team->id,
            agentAddress: 'agent1qremote',
        );

        $this->assertSame($first->id, $second->id);
    }

    public function test_install_rejects_team_without_credential(): void
    {
        $otherTeam = Team::create([
            'name' => 'NoKey',
            'slug' => 'nokey-'.Str::random(4),
            'owner_id' => $this->team->owner_id,
            'settings' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active Agentverse credential');
        app(InstallFromAgentverseAction::class)->execute(
            teamId: $otherTeam->id,
            agentAddress: 'agent1qremote',
        );
    }

    public function test_dispatcher_routes_agentverse_mailbox_to_v2_endpoint(): void
    {
        Http::fake([
            AgentverseClient::BASE_URL.'/agents/mailbox/submit' => Http::response([
                'payload' => [
                    'msg_id' => (string) Str::uuid7(),
                    'content' => 'remote reply via mailbox',
                ],
            ], 200),
            AgentverseClient::BASE_URL.'/agents/agent1qremote' => Http::response([
                'name' => 'Mailbox Agent',
            ], 200),
        ]);

        $agent = app(InstallFromAgentverseAction::class)->execute(
            teamId: $this->team->id,
            agentAddress: 'agent1qremote',
        );

        $result = app(DispatchChatMessageAction::class)->execute(
            externalAgent: $agent,
            content: 'hello via agentverse',
        );

        $this->assertSame('remote reply via mailbox', $result['remote_response']['content'] ?? null);

        Http::assertSent(function ($req) {
            return str_ends_with($req->url(), '/v2/agents/mailbox/submit')
                && $req->hasHeader('Authorization')
                && str_contains($req['target'] ?? '', 'agent1qremote');
        });
    }
}
