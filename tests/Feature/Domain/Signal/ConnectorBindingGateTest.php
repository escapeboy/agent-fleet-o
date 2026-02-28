<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Outbound\Actions\SendOutboundAction;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\ConnectorBindingStatus;
use App\Domain\Signal\Models\ConnectorBinding;
use App\Domain\Signal\Services\ConnectorBindingGate;
use App\Domain\Telegram\Models\TelegramBot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectorBindingGateTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ConnectorBindingGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name'     => 'Test Team',
            'slug'     => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->gate = app(ConnectorBindingGate::class);
    }

    public function test_bypass_source_types_are_approved_without_creating_binding(): void
    {
        foreach (['imap', 'rss', 'webhook', 'api_polling', 'github_issues'] as $channel) {
            $result = $this->gate->check($this->team->id, $channel, 'some-external-id');
            $this->assertSame('approved', $result, "Expected bypass for channel: {$channel}");
        }
    }

    public function test_telegram_sends_pairing_reply_via_bot_api(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        TelegramBot::create([
            'team_id'      => $this->team->id,
            'bot_token'    => 'test-token-123',
            'bot_username' => 'testbot',
            'bot_name'     => 'Test Bot',
            'status'       => 'active',
            'routing_mode' => 'assistant',
        ]);

        $result = $this->gate->check($this->team->id, 'telegram', '123456789');

        $this->assertSame('pending', $result);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_whatsapp_sends_pairing_reply_via_outbound_action(): void
    {
        $this->assertOutboundPairingReplyIsSent('whatsapp', '+1234567890', 'phone');
    }

    public function test_discord_sends_pairing_reply_via_outbound_action(): void
    {
        $this->assertOutboundPairingReplyIsSent('discord', '987654321012345678', 'recipient');
    }

    public function test_matrix_sends_pairing_reply_via_outbound_action(): void
    {
        $this->assertOutboundPairingReplyIsSent('matrix', '!abc123:matrix.org', 'room_id');
    }

    public function test_signal_protocol_sends_pairing_reply_via_outbound_action(): void
    {
        $this->assertOutboundPairingReplyIsSent('signal_protocol', '+441234567890', 'recipient');
    }

    public function test_unknown_channel_sends_no_reply(): void
    {
        $sendAction = $this->mock(SendOutboundAction::class);
        $sendAction->shouldNotReceive('execute');

        Http::fake();

        $result = $this->gate->check($this->team->id, 'fax', 'some-fax-number');

        $this->assertSame('pending', $result);
        Http::assertNothingSent();
    }

    public function test_known_approved_sender_returns_approved_without_new_reply(): void
    {
        $sendAction = $this->mock(SendOutboundAction::class);
        $sendAction->shouldReceive('execute')->once()->andReturn(new OutboundAction);

        // First visit: unknown sender → pending + reply sent
        $this->gate->check($this->team->id, 'discord', '111222333');

        // Manually approve the binding
        ConnectorBinding::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('channel', 'discord')
            ->where('external_id', '111222333')
            ->update(['status' => ConnectorBindingStatus::Approved]);

        // Second visit: approved → no new reply
        $result = $this->gate->check($this->team->id, 'discord', '111222333');

        $this->assertSame('approved', $result);
        // execute() was only called once (for the first check), not again
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function assertOutboundPairingReplyIsSent(
        string $channel,
        string $externalId,
        string $expectedTargetKey,
    ): void {
        $sendAction = $this->mock(SendOutboundAction::class);
        $sendAction->shouldReceive('execute')
            ->once()
            ->withArgs(function (OutboundProposal $proposal) use ($channel, $externalId, $expectedTargetKey) {
                return $proposal->channel === OutboundChannel::from($channel)
                    && ($proposal->target[$expectedTargetKey] ?? null) === $externalId
                    && str_contains($proposal->content['text'], 'pairing code');
            })
            ->andReturn(new OutboundAction);

        $result = $this->gate->check($this->team->id, $channel, $externalId);

        $this->assertSame('pending', $result);
    }
}
