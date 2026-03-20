<?php

namespace Tests\Feature\Domain\Outbound;

use App\Domain\Outbound\Connectors\SupabaseRealtimeConnector;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseRealtimeConnectorTest extends TestCase
{
    use RefreshDatabase;

    private SupabaseRealtimeConnector $connector;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->connector = app(SupabaseRealtimeConnector::class);
    }

    public function test_supports_supabase_realtime_channel(): void
    {
        $this->assertTrue($this->connector->supports('supabase_realtime'));
    }

    public function test_does_not_support_other_channels(): void
    {
        $this->assertFalse($this->connector->supports('email'));
        $this->assertFalse($this->connector->supports('telegram'));
    }

    public function test_send_broadcasts_to_supabase_realtime_rest_api(): void
    {
        Http::fake([
            '*.supabase.co/realtime/v1/api/broadcast' => Http::response(['ok' => true], 200),
        ]);

        $proposal = OutboundProposal::factory()->for($this->team)->create([
            'channel' => OutboundChannel::SupabaseRealtime,
            'target' => [
                'ref' => 'xyzabcdef',
                'channel' => 'agent-updates',
                'event' => 'task_complete',
                'key' => 'service-role-key',
            ],
            'content' => ['result' => 'done'],
            'status' => OutboundProposalStatus::Approved,
        ]);

        $action = $this->connector->send($proposal);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'xyzabcdef.supabase.co/realtime/v1/api/broadcast')
                && $request->hasHeader('apikey');
        });

        $this->assertSame('sent', $action->fresh()->status->value);
    }

    public function test_send_fails_when_target_missing_ref(): void
    {
        Http::fake();

        $proposal = OutboundProposal::factory()->for($this->team)->create([
            'channel' => OutboundChannel::SupabaseRealtime,
            'target' => ['channel' => 'test'],
            'content' => ['data' => 'value'],
            'status' => OutboundProposalStatus::Approved,
        ]);

        $action = $this->connector->send($proposal);

        $this->assertSame('failed', $action->fresh()->status->value);
        Http::assertNothingSent();
    }

    public function test_send_is_idempotent_on_duplicate_calls(): void
    {
        Http::fake([
            '*.supabase.co/realtime/v1/api/broadcast' => Http::response(['ok' => true], 200),
        ]);

        $proposal = OutboundProposal::factory()->for($this->team)->create([
            'channel' => OutboundChannel::SupabaseRealtime,
            'target' => ['ref' => 'abc', 'channel' => 'ch', 'event' => 'ev', 'key' => 'k'],
            'content' => ['x' => 1],
            'status' => OutboundProposalStatus::Approved,
        ]);

        $action1 = $this->connector->send($proposal);
        $action2 = $this->connector->send($proposal);

        $this->assertSame($action1->id, $action2->id);
    }
}
