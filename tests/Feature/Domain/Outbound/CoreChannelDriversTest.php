<?php

namespace Tests\Feature\Domain\Outbound;

use App\Domain\Outbound\Connectors\DiscordConnector;
use App\Domain\Outbound\Connectors\DummyConnector;
use App\Domain\Outbound\Connectors\GoogleChatConnector;
use App\Domain\Outbound\Connectors\SlackConnector;
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

}
