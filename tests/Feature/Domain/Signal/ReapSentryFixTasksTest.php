<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\SentryFixTaskState;
use App\Domain\Signal\Models\SentryFixTask;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F8: lease reaping.
 */
class ReapSentryFixTasksTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $owner->id]);
    }

    private function task(SentryFixTaskState $state, int $attempts, ?\DateTimeInterface $lease): SentryFixTask
    {
        $this->seq++;

        return SentryFixTask::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'sentry_issue_id' => 'issue-'.$this->seq,
            'repository' => 'acme/shop',
            'title' => 'Issue',
            'state' => $state,
            'attempts' => $attempts,
            'claimed_by' => 'fixer-1',
            'lease_expires_at' => $lease,
            'events' => [],
        ]);
    }

    public function test_expired_lease_with_one_attempt_goes_back_to_queued(): void
    {
        $task = $this->task(SentryFixTaskState::Fixing, 1, now()->subMinute());

        $this->artisan('sentry-fix-tasks:reap')->assertSuccessful();

        $task->refresh();
        $this->assertSame(SentryFixTaskState::Queued, $task->state);
        $this->assertNull($task->claimed_by);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame(1, $task->attempts);
        $this->assertSame('queued', $task->events[0]['state']);
    }

    public function test_expired_lease_with_two_attempts_fails(): void
    {
        $task = $this->task(SentryFixTaskState::Claimed, 2, now()->subMinute());

        $this->artisan('sentry-fix-tasks:reap')->assertSuccessful();

        $this->assertSame(SentryFixTaskState::Failed, $task->refresh()->state);
    }

    public function test_awaiting_approval_is_never_reaped_even_with_an_old_lease(): void
    {
        $task = $this->task(SentryFixTaskState::AwaitingApproval, 2, now()->subDay());

        $this->artisan('sentry-fix-tasks:reap')->assertSuccessful();

        $this->assertSame(SentryFixTaskState::AwaitingApproval, $task->refresh()->state);
    }

    public function test_live_leases_queued_approved_and_terminal_tasks_are_untouched(): void
    {
        $live = $this->task(SentryFixTaskState::Fixing, 1, now()->addHour());
        $queued = $this->task(SentryFixTaskState::Queued, 0, null);
        $approved = $this->task(SentryFixTaskState::Approved, 1, now()->subDay());
        $done = $this->task(SentryFixTaskState::Done, 1, now()->subDay());

        $this->artisan('sentry-fix-tasks:reap')->assertSuccessful();

        $this->assertSame(SentryFixTaskState::Fixing, $live->refresh()->state);
        $this->assertSame(SentryFixTaskState::Queued, $queued->refresh()->state);
        $this->assertSame(SentryFixTaskState::Approved, $approved->refresh()->state);
        $this->assertSame(SentryFixTaskState::Done, $done->refresh()->state);
    }

    public function test_expired_lease_after_merge_started_is_escalated_not_requeued(): void
    {
        $task = $this->task(SentryFixTaskState::Watching, 1, now()->subMinute());

        $this->artisan('sentry-fix-tasks:reap')->assertSuccessful();

        $this->assertSame(SentryFixTaskState::Escalated, $task->refresh()->state);
    }

    public function test_lease_renewed_after_selection_is_not_reaped(): void
    {
        $task = $this->task(SentryFixTaskState::Fixing, 1, now()->subMinute());
        $renewed = now()->addHours(3);
        $fired = false;

        // A worker event lands between the reaper's selection and its locked re-read.
        DB::listen(function ($query) use ($task, $renewed, &$fired): void {
            if ($fired || ! str_contains($query->sql, 'lease_expires_at')) {
                return;
            }
            $fired = true;
            DB::table('sentry_fix_tasks')->where('id', $task->id)->update(['lease_expires_at' => $renewed]);
        });

        $this->artisan('sentry-fix-tasks:reap')->assertSuccessful();

        $task->refresh();
        $this->assertTrue($fired);
        $this->assertSame(SentryFixTaskState::Fixing, $task->state);
        $this->assertSame('fixer-1', $task->claimed_by);
        $this->assertSame([], $task->events);
    }

    public function test_task_that_left_a_reapable_state_after_selection_is_not_touched(): void
    {
        $task = $this->task(SentryFixTaskState::Fixing, 1, now()->subMinute());
        $fired = false;

        DB::listen(function ($query) use ($task, &$fired): void {
            if ($fired || ! str_contains($query->sql, 'lease_expires_at')) {
                return;
            }
            $fired = true;
            DB::table('sentry_fix_tasks')->where('id', $task->id)->update(['state' => 'awaiting_approval', 'lease_expires_at' => null]);
        });

        $this->artisan('sentry-fix-tasks:reap')->assertSuccessful();

        $this->assertSame(SentryFixTaskState::AwaitingApproval, $task->refresh()->state);
    }

    public function test_command_is_scheduled_every_fifteen_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'sentry-fix-tasks:reap'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
    }
}
