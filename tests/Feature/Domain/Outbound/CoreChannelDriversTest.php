<?php

namespace Tests\Feature\Domain\Outbound;

use App\Domain\Outbound\Connectors\DiscordConnector;
use App\Domain\Outbound\Connectors\DummyConnector;
use App\Domain\Outbound\Connectors\GoogleChatConnector;
use App\Domain\Outbound\Connectors\MatrixConnector;
use App\Domain\Outbound\Connectors\SignalProtocolConnector;
use App\Domain\Outbound\Connectors\SlackConnector;
use App\Domain\Outbound\Connectors\SupabaseRealtimeConnector;
use App\Domain\Outbound\Connectors\TeamsConnector;
use App\Domain\Outbound\Connectors\TelegramConnector;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Managers\OutboundConnectorManager;
use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoreChannelDriversTest extends TestCase
{
    use RefreshDatabase;

    private OutboundConnectorManager $manager;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(OutboundConnectorManager::class);
        $this->team = Team::factory()->create();
    }

    /**
     * Each formerly-"legacy" channel must now resolve to its real connector,
     * not the DummyConnector fallback.
     *
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function coreChannelProvider(): array
    {
        return [
            'telegram' => ['telegram', TelegramConnector::class],
            'slack' => ['slack', SlackConnector::class],
            'discord' => ['discord', DiscordConnector::class],
            'teams' => ['teams', TeamsConnector::class],
            'google_chat' => ['google_chat', GoogleChatConnector::class],
            'matrix' => ['matrix', MatrixConnector::class],
            'signal_protocol' => ['signal_protocol', SignalProtocolConnector::class],
            'supabase_realtime' => ['supabase_realtime', SupabaseRealtimeConnector::class],
        ];
    }

    /**
     * @dataProvider coreChannelProvider
     *
     * @param  class-string  $expected
     */
    public function test_connector_for_returns_real_connector(string $channel, string $expected): void
    {
        $connector = $this->manager->connectorFor($channel);

        $this->assertInstanceOf($expected, $connector);
        $this->assertNotInstanceOf(DummyConnector::class, $connector);
        $this->assertTrue($this->manager->hasConnector($channel));
    }

    /**
     * @dataProvider coreChannelProvider
     */
    public function test_channel_enum_is_core(string $channel): void
    {
        $this->assertTrue(OutboundChannel::from($channel)->isCore());
    }

    private function configFor(string $channel, array $credentials): void
    {
        OutboundConnectorConfig::create([
            'team_id' => $this->team->id,
            'channel' => $channel,
            'credentials' => $credentials,
            'is_active' => true,
        ]);
    }

    private function proposal(OutboundChannel $channel, array $target): OutboundProposal
    {
        return OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'channel' => $channel,
            'target' => $target,
            'content' => ['body' => 'Hello from FleetQ'],
        ]);
    }

    public function test_telegram_send_uses_resolved_config_and_fakes_http(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200)]);
        $this->configFor('telegram', ['bot_token' => 'test-bot-token']);

        $proposal = $this->proposal(OutboundChannel::Telegram, ['chat_id' => '123456']);
        $action = app(TelegramConnector::class)->send($proposal);

        $this->assertSame(OutboundActionStatus::Sent, $action->status);
        $this->assertSame('42', $action->external_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org/bottest-bot-token/sendMessage'));
    }

    public function test_slack_send_uses_resolved_config_and_fakes_http(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        $this->configFor('slack', ['webhook_url' => 'https://hooks.slack.com/services/T/B/X']);

        $proposal = $this->proposal(OutboundChannel::Slack, []);
        $action = app(SlackConnector::class)->send($proposal);

        $this->assertSame(OutboundActionStatus::Sent, $action->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com/services/T/B/X'));
    }

    public function test_matrix_send_uses_resolved_config_and_fakes_http(): void
    {
        Http::fake(['matrix.example.org/*' => Http::response(['event_id' => '$evt:matrix.example.org'], 200)]);
        $this->configFor('matrix', [
            'homeserver_url' => 'https://matrix.example.org',
            'access_token' => 'cfg-token',
            'room_id' => '!cfgroom:matrix.example.org',
        ]);

        // Empty target — connector must fall back to resolved config creds.
        $proposal = $this->proposal(OutboundChannel::Matrix, []);
        $action = app(MatrixConnector::class)->send($proposal);

        $this->assertSame(OutboundActionStatus::Sent, $action->status);
        $this->assertSame('$evt:matrix.example.org', $action->external_id);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'matrix.example.org/_matrix/client/v3/rooms/')
                && str_contains($request->url(), urlencode('!cfgroom:matrix.example.org'))
                && $request->hasHeader('Authorization', 'Bearer cfg-token');
        });
    }

    public function test_signal_send_uses_resolved_config_and_fakes_http(): void
    {
        Http::fake(['signal.example.org:8080/*' => Http::response(['timestamp' => 1700000000], 201)]);
        $this->configFor('signal_protocol', [
            'api_url' => 'http://signal.example.org:8080',
            'phone_number' => '+15551112222',
            'recipient' => '+15553334444',
        ]);

        // Empty target — connector must fall back to resolved config creds.
        $proposal = $this->proposal(OutboundChannel::SignalProtocol, []);
        $action = app(SignalProtocolConnector::class)->send($proposal);

        $this->assertSame(OutboundActionStatus::Sent, $action->status);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'signal.example.org:8080/v2/send')
                && $request['number'] === '+15551112222'
                && $request['recipients'] === ['+15553334444'];
        });
    }

    public function test_supabase_realtime_send_uses_resolved_config_and_fakes_http(): void
    {
        Http::fake(['cfgref.supabase.co/*' => Http::response('', 202)]);
        $this->configFor('supabase_realtime', [
            'ref' => 'cfgref',
            'key' => 'cfg-key',
            'channel' => 'cfg:channel',
            'event' => 'cfg_event',
        ]);

        // Empty target — connector must fall back to resolved config creds.
        $proposal = $this->proposal(OutboundChannel::SupabaseRealtime, []);
        $action = app(SupabaseRealtimeConnector::class)->send($proposal);

        $this->assertSame(OutboundActionStatus::Sent, $action->status);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'cfgref.supabase.co/realtime/v1/api/broadcast')
                && $request->hasHeader('apikey', 'cfg-key')
                && $request['messages'][0]['topic'] === 'cfg:channel'
                && $request['messages'][0]['event'] === 'cfg_event';
        });
    }
}
