<?php

namespace Tests\Feature\Domain\Shared;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\UserNotification;
use App\Domain\Shared\Services\NotificationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // Prevent push notification jobs from running

        $this->service = app(NotificationService::class);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_notify_creates_in_app_notification(): void
    {
        $notification = $this->service->notify(
            userId: $this->user->id,
            teamId: $this->team->id,
            type: 'agent.risk.high',
            title: 'Agent disabled',
            body: 'Risk score exceeded 80',
            actionUrl: '/agents/123',
        );

        $this->assertNotNull($notification);
        $this->assertInstanceOf(UserNotification::class, $notification);
        $this->assertEquals($this->user->id, $notification->user_id);
        $this->assertEquals($this->team->id, $notification->team_id);
        $this->assertEquals('Agent disabled', $notification->title);
        $this->assertNull($notification->read_at);
    }

    public function test_notify_returns_null_for_unknown_user(): void
    {
        $result = $this->service->notify(
            userId: 'non-existent-user-id',
            teamId: $this->team->id,
            type: 'agent.risk.high',
            title: 'Test',
            body: 'Test body',
        );

        $this->assertNull($result);
    }

    public function test_notify_team_creates_notification_for_each_member(): void
    {
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $this->team->users()->attach($member1, ['role' => 'member']);
        $this->team->users()->attach($member2, ['role' => 'member']);

        $results = $this->service->notifyTeam(
            teamId: $this->team->id,
            type: 'agent.risk.high',
            title: 'Team Alert',
            body: 'Something happened',
        );

        // owner + 2 members = 3 users
        $this->assertGreaterThanOrEqual(3, $results->count());
    }

    public function test_notify_team_excludes_specified_users(): void
    {
        $member = User::factory()->create();
        $this->team->users()->attach($member, ['role' => 'member']);

        $results = $this->service->notifyTeam(
            teamId: $this->team->id,
            type: 'agent.risk.high',
            title: 'Team Alert',
            body: 'Something happened',
            excludeUserIds: [$member->id],
        );

        $notifiedIds = $results->map(fn ($n) => $n->user_id)->toArray();
        $this->assertNotContains($member->id, $notifiedIds);
    }

    public function test_unread_count_returns_correct_count(): void
    {
        $this->service->notify($this->user->id, $this->team->id, 'agent.risk.high', 'T1', 'B1');
        $this->service->notify($this->user->id, $this->team->id, 'agent.risk.high', 'T2', 'B2');

        $count = $this->service->unreadCount($this->user->id, $this->team->id);

        $this->assertEquals(2, $count);
    }

    public function test_mark_all_read_clears_unread_notifications(): void
    {
        $this->service->notify($this->user->id, $this->team->id, 'agent.risk.high', 'T1', 'B1');
        $this->service->notify($this->user->id, $this->team->id, 'agent.risk.high', 'T2', 'B2');

        $this->assertEquals(2, $this->service->unreadCount($this->user->id, $this->team->id));

        $this->service->markAllRead($this->user->id, $this->team->id);

        $this->assertEquals(0, $this->service->unreadCount($this->user->id, $this->team->id));

        $all = UserNotification::where('user_id', $this->user->id)->get();
        foreach ($all as $n) {
            $this->assertNotNull($n->read_at);
        }
    }

    public function test_notification_mark_as_read_method(): void
    {
        $notification = $this->service->notify(
            $this->user->id,
            $this->team->id,
            'agent.risk.high',
            'Title',
            'Body',
        );

        $this->assertFalse($notification->isRead());

        $notification->markAsRead();

        $this->assertTrue($notification->isRead());
        $this->assertNotNull($notification->read_at);
    }
}
