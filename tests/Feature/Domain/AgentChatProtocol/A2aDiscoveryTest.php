<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Actions\DiscoverA2aAgentAction;
use App\Domain\AgentChatProtocol\DTOs\ChatMessageDTO;
use App\Domain\AgentChatProtocol\Enums\AdapterKind;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Exceptions\A2aDiscoveryException;
use App\Domain\AgentChatProtocol\Exceptions\A2aDispatchNotSupportedException;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\AgentChatProtocol\Services\ProtocolDispatcher;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class A2aDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'A2A Discovery',
            'slug' => 'a2a-discovery-'.substr(Str::uuid7()->toString(), 0, 8),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        // Pin SSRF off by default so happy-path discovery is deterministic across
        // editions (the cloud container forces validate_host on). The dedicated
        // SSRF test re-enables it explicitly.
        config(['services.ssrf.validate_host' => false]);
    }

    /** @return array<string, mixed> */
    private function sampleCard(): array
    {
        return [
            'name' => 'Recipe Agent',
            'description' => 'Helps with recipes.',
            'version' => '2.0.0',
            'supportedInterfaces' => [
                ['url' => 'https://recipe.example.com/a2a/v1', 'protocolBinding' => 'JSONRPC'],
            ],
            'securitySchemes' => ['Bearer' => ['type' => 'http']],
            'capabilities' => ['streaming' => true],
            'skills' => [['id' => 'suggest', 'name' => 'Suggest Recipe']],
        ];
    }

    public function test_discovery_disabled_by_default_throws_and_creates_nothing(): void
    {
        config(['agent_chat.a2a.discovery_enabled' => false]);
        Http::fake();

        try {
            app(DiscoverA2aAgentAction::class)->execute($this->team->id, 'https://recipe.example.com');
            $this->fail('Expected A2aDiscoveryException when flag is off.');
        } catch (A2aDiscoveryException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }

        $this->assertSame(0, ExternalAgent::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_discovers_and_registers_a2a_agent_from_well_known_uri(): void
    {
        config(['agent_chat.a2a.discovery_enabled' => true]);
        Http::fake([
            'https://recipe.example.com/.well-known/agent-card.json' => Http::response($this->sampleCard(), 200),
        ]);

        $agent = app(DiscoverA2aAgentAction::class)->execute($this->team->id, 'https://recipe.example.com');

        $this->assertSame('Recipe Agent', $agent->name);
        $this->assertSame(AdapterKind::A2a, $agent->adapter_kind);
        $this->assertSame('https://recipe.example.com/a2a/v1', $agent->endpoint_url);
        $this->assertSame('a2a', $agent->protocol_version);
        $this->assertSame(ExternalAgentStatus::Active, $agent->status);
        $this->assertTrue($agent->capabilities['streaming']);
        $this->assertSame(['Bearer'], $agent->capabilities['security_schemes']);
        // Raw card preserved for the future dispatch slice.
        $this->assertSame('Recipe Agent', $agent->manifest_cached['name']);
        $this->assertNotNull($agent->manifest_fetched_at);
    }

    public function test_appends_well_known_path_to_bare_domain(): void
    {
        config(['agent_chat.a2a.discovery_enabled' => true]);
        Http::fake([
            'https://bare.example.com/.well-known/agent-card.json' => Http::response($this->sampleCard(), 200),
        ]);

        app(DiscoverA2aAgentAction::class)->execute($this->team->id, 'https://bare.example.com/');

        Http::assertSent(fn ($request) => $request->url() === 'https://bare.example.com/.well-known/agent-card.json');
    }

    public function test_accepts_full_card_url_unchanged(): void
    {
        config(['agent_chat.a2a.discovery_enabled' => true]);
        Http::fake([
            'https://x.example.com/.well-known/agent.json' => Http::response($this->sampleCard(), 200),
        ]);

        app(DiscoverA2aAgentAction::class)->execute($this->team->id, 'https://x.example.com/.well-known/agent.json');

        Http::assertSent(fn ($request) => $request->url() === 'https://x.example.com/.well-known/agent.json');
    }

    public function test_ssrf_guard_blocks_private_host_when_enabled(): void
    {
        config([
            'agent_chat.a2a.discovery_enabled' => true,
            'services.ssrf.validate_host' => true,
        ]);
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        try {
            app(DiscoverA2aAgentAction::class)->execute($this->team->id, 'http://127.0.0.1');
        } finally {
            $this->assertSame(0, ExternalAgent::withoutGlobalScopes()->count());
            Http::assertNothingSent();
        }
    }

    public function test_failed_http_throws_discovery_exception(): void
    {
        config(['agent_chat.a2a.discovery_enabled' => true]);
        Http::fake([
            'https://recipe.example.com/.well-known/agent-card.json' => Http::response('nope', 404),
        ]);

        $this->expectException(A2aDiscoveryException::class);

        app(DiscoverA2aAgentAction::class)->execute($this->team->id, 'https://recipe.example.com');
    }

    public function test_discovery_is_idempotent_by_endpoint(): void
    {
        config(['agent_chat.a2a.discovery_enabled' => true]);
        Http::fake([
            'https://recipe.example.com/.well-known/agent-card.json' => Http::response($this->sampleCard(), 200),
        ]);

        $action = app(DiscoverA2aAgentAction::class);
        $first = $action->execute($this->team->id, 'https://recipe.example.com');
        $second = $action->execute($this->team->id, 'https://recipe.example.com');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ExternalAgent::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_two_peers_sharing_a_card_name_do_not_collide_on_slug(): void
    {
        config(['agent_chat.a2a.discovery_enabled' => true]);

        // The suffix used to come from the head of a UUIDv7, which is a
        // millisecond timestamp — identical for anything registered within the
        // same ~4.6 hours. Two same-named cards then hit the (team_id, slug)
        // unique index and 500'd instead of both registering.
        $cardOne = $this->sampleCard();
        $cardOne['supportedInterfaces'] = [['url' => 'https://one.example.com/a2a/v1', 'protocolBinding' => 'JSONRPC']];
        $cardTwo = $this->sampleCard();
        $cardTwo['supportedInterfaces'] = [['url' => 'https://two.example.com/a2a/v1', 'protocolBinding' => 'JSONRPC']];

        Http::fake([
            'https://one.example.com/.well-known/agent-card.json' => Http::response($cardOne, 200),
            'https://two.example.com/.well-known/agent-card.json' => Http::response($cardTwo, 200),
        ]);

        $action = app(DiscoverA2aAgentAction::class);
        $first = $action->execute($this->team->id, 'https://one.example.com');
        $second = $action->execute($this->team->id, 'https://two.example.com');

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->slug, $second->slug);
        $this->assertSame(2, ExternalAgent::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_dispatching_to_a2a_agent_is_guarded(): void
    {
        // Stated rather than inherited: this asserts the guard, so it must not
        // depend on the ambient default (a developer with A2A_DISPATCH_ENABLED
        // in their .env otherwise sees a real outbound call and a confusing
        // ConnectionException here).
        config(['agent_chat.a2a.dispatch_enabled' => false]);

        $agent = ExternalAgent::create([
            'id' => Str::uuid7()->toString(),
            'team_id' => $this->team->id,
            'name' => 'A2A Agent',
            'slug' => 'a2a-agent-'.substr(Str::uuid7()->toString(), 0, 6),
            'endpoint_url' => 'https://recipe.example.com/a2a/v1',
            'adapter_kind' => AdapterKind::A2a->value,
            'protocol_version' => 'a2a',
            'status' => ExternalAgentStatus::Active,
        ]);

        $message = ChatMessageDTO::fromArray([
            'session_id' => 'sess-1',
            'from' => 'fleetq:test',
            'to' => $agent->endpoint_url,
            'content' => 'hello',
        ]);

        $this->expectException(A2aDispatchNotSupportedException::class);

        app(ProtocolDispatcher::class)->sendChat($agent, $message);
    }
}
