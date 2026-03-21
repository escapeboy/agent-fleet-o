<?php

namespace Tests\Feature\Domain\Outbound;

use App\Domain\Outbound\Connectors\NotificationConnector;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NotificationConnectorTest extends TestCase
{
    use RefreshDatabase;

    private NotificationConnector $connector;

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

        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->shouldReceive('notifyTeam')->andReturn(collect());

        $this->connector = new NotificationConnector($notificationService);
    }

    public function test_supports_notification_channel(): void
    {
        $this->assertTrue($this->connector->supports('notification'));
    }

    public function test_does_not_support_other_channels(): void
    {
        $this->assertFalse($this->connector->supports('email'));
        $this->assertFalse($this->connector->supports('telegram'));
        $this->assertFalse($this->connector->supports('slack'));
        $this->assertFalse($this->connector->supports('webhook'));
    }

    public function test_send_creates_outbound_action_with_sent_status(): void
    {
        $proposal = OutboundProposal::factory()->for($this->team)->create([
            'channel' => OutboundChannel::Notification,
            'target' => [],
            'content' => ['subject' => 'Test Title', 'body' => 'Test body content'],
            'status' => OutboundProposalStatus::Approved,
        ]);

        $action = $this->connector->send($proposal);

        $this->assertSame(OutboundActionStatus::Sent->value, $action->fresh()->status->value);
        $this->assertSame('notification-'.$proposal->id, $action->external_id);
        $this->assertSame('in_app_notification', $action->response['channel']);
        $this->assertTrue($action->response['delivered']);
    }

    public function test_send_is_idempotent_on_duplicate_calls(): void
    {
        $proposal = OutboundProposal::factory()->for($this->team)->create([
            'channel' => OutboundChannel::Notification,
            'target' => [],
            'content' => ['subject' => 'Test', 'body' => 'Body'],
            'status' => OutboundProposalStatus::Approved,
        ]);

        $action1 = $this->connector->send($proposal);
        $action2 = $this->connector->send($proposal);

        $this->assertSame($action1->id, $action2->id);
    }

    public function test_send_uses_fallback_title_when_subject_missing(): void
    {
        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->shouldReceive('notifyTeam')
            ->once()
            ->withArgs(function ($teamId, $type, $title) {
                return $title === 'Experiment Result';
            })
            ->andReturn(collect());

        $connector = new NotificationConnector($notificationService);

        $proposal = OutboundProposal::factory()->for($this->team)->create([
            'channel' => OutboundChannel::Notification,
            'target' => [],
            'content' => ['body' => 'Some body'],
            'status' => OutboundProposalStatus::Approved,
        ]);

        $connector->send($proposal);
    }
}
