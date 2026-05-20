<?php

namespace Tests\Feature\Domain\Broadcast;

use App\Domain\Audience\Actions\AddAudienceMember;
use App\Domain\Audience\Actions\UnsubscribeContact;
use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Actions\ApproveBroadcast;
use App\Domain\Broadcast\Actions\CancelBroadcast;
use App\Domain\Broadcast\Actions\CreateBroadcast;
use App\Domain\Broadcast\Actions\RequestBroadcastApproval;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Jobs\SendBroadcastJob;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Broadcast\Models\BroadcastRecipient;
use App\Domain\Broadcast\Services\BroadcastBudgetGuard;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Audience $audience;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->audience = Audience::factory()->create(['team_id' => $this->team->id]);
    }

    private function subscribe(int $count): void
    {
        $add = app(AddAudienceMember::class);
        for ($i = 0; $i < $count; $i++) {
            $add->execute($this->audience, ContactIdentity::factory()->create(['team_id' => $this->team->id]));
        }
    }

    private function draft(): Broadcast
    {
        return app(CreateBroadcast::class)->execute($this->audience, 'Launch', 'Hello', '<p>Hi</p>');
    }

    public function test_budget_guard_rejects_zero_and_over_cap(): void
    {
        $guard = app(BroadcastBudgetGuard::class);

        $this->expectException(InsufficientBudgetException::class);
        $guard->assertCanSend($this->team->id, 0);
    }

    public function test_budget_guard_rejects_over_recipient_cap(): void
    {
        $this->expectException(InsufficientBudgetException::class);
        app(BroadcastBudgetGuard::class)->assertCanSend($this->team->id, BroadcastBudgetGuard::MAX_RECIPIENTS + 1);
    }

    public function test_request_approval_moves_draft_to_pending_with_recipient_count(): void
    {
        $this->subscribe(3);
        $broadcast = app(RequestBroadcastApproval::class)->execute($this->draft(), 'user-1');

        $this->assertSame(BroadcastStatus::PendingApproval, $broadcast->status);
        $this->assertSame(3, $broadcast->recipient_count);
    }

    public function test_request_approval_fails_with_no_subscribers(): void
    {
        $this->expectException(InsufficientBudgetException::class);
        app(RequestBroadcastApproval::class)->execute($this->draft(), 'user-1');
    }

    public function test_request_approval_rejects_non_draft_broadcast(): void
    {
        $this->subscribe(1);
        $broadcast = app(RequestBroadcastApproval::class)->execute($this->draft(), 'user-1');

        $this->expectException(\RuntimeException::class);
        app(RequestBroadcastApproval::class)->execute($broadcast, 'user-1');
    }

    public function test_approve_materializes_one_recipient_per_subscribed_member(): void
    {
        Queue::fake();
        $this->subscribe(2);
        $unsubscribed = ContactIdentity::factory()->create(['team_id' => $this->team->id]);
        app(AddAudienceMember::class)->execute($this->audience, $unsubscribed);
        app(UnsubscribeContact::class)->execute($this->team->id, $unsubscribed);

        $broadcast = app(RequestBroadcastApproval::class)->execute($this->draft(), 'user-1');
        $broadcast = app(ApproveBroadcast::class)->execute($broadcast, 'admin-1');

        $this->assertSame(BroadcastStatus::Sending, $broadcast->status);
        $this->assertSame(2, BroadcastRecipient::withoutGlobalScopes()
            ->where('broadcast_id', $broadcast->id)->count());
        Queue::assertPushed(SendBroadcastJob::class);
    }

    public function test_cancel_rejects_a_sent_broadcast(): void
    {
        $broadcast = $this->draft();
        $broadcast->update(['status' => BroadcastStatus::Sent]);

        $this->expectException(\RuntimeException::class);
        app(CancelBroadcast::class)->execute($broadcast);
    }
}
